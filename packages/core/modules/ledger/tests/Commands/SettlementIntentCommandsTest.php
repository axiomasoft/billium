<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Commands;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use PDOException;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class SettlementIntentCommandsTest extends LedgerTestCase
{
    public function test_ordered_intent_replays_and_different_order_conflicts(): void
    {
        $a = $this->obligation('a');
        $b = $this->obligation('b');
        $cmd = new DeclareSettlementIntent('intent', $this->account(), 'business', 'RUB', ['b', 'a']);
        $first = $this->ledger()->declareSettlementIntent($cmd);
        self::assertSame($first->body, $this->ledger()->declareSettlementIntent($cmd)->body);
        $targets = $this->table('SELECT * FROM billium_settlement_target ORDER BY position');
        $businessReplay = $this->ledger()->declareSettlementIntent(new DeclareSettlementIntent('intent-replay', $this->account(), 'business', 'RUB', ['b', 'a']));
        self::assertSame($first->body, $businessReplay->body);
        self::assertSame($targets, $this->table('SELECT * FROM billium_settlement_target ORDER BY position'));
        self::assertSame(1, $this->rows('billium_settlement_intent'));
        self::assertSame(1, $this->rows('billium_allocation_receipt', "kind='settlement_intent'"));
        self::assertSame([$b, $a], array_map(static fn (array $r): int => self::integer($r['obligation_id']), $this->table('SELECT obligation_id FROM billium_settlement_target ORDER BY position')));
        try {
            $this->ledger()->declareSettlementIntent(new DeclareSettlementIntent('changed', $this->account(), 'business', 'RUB', ['a', 'b']));
            self::fail('target order conflicts');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
        }
    }

    public function test_guarded_intent_replay_checks_ordered_targets_independently_of_digest(): void
    {
        $a = $this->obligation('a');
        $b = $this->obligation('b');
        $this->ledger()->declareSettlementIntent(new DeclareSettlementIntent('intent', $this->account(), 'business', 'RUB', ['b', 'a']));
        $targets = $this->table('SELECT * FROM billium_settlement_target ORDER BY position');
        $digest = self::text($this->value('SELECT semantic_digest FROM billium_settlement_intent'));
        $args = [...$this->scopeArguments(), 'business', 'pro_rata_open/1', '{'.$b.','.$a.'}', $digest];
        $replay = $this->routine('declare_settlement_intent', $args);
        self::assertTrue($replay['replayed']);
        foreach (['{'.$a.','.$b.'}', '{'.$b.'}'] as $changedTargets) {
            $changed = $args;
            $changed[7] = $changedTargets;
            try {
                $this->routine('declare_settlement_intent', array_values($changed));
                self::fail('changed target order or membership must conflict even with the original digest');
            } catch (PDOException $e) {
                self::assertSame('P0001', $e->errorInfo[0] ?? null);
                self::assertStringContainsString('billium.idempotency.conflict', $e->getMessage());
            }
        }
        self::assertSame($targets, $this->table('SELECT * FROM billium_settlement_target ORDER BY position'));
        self::assertSame(1, self::integer($this->value('SELECT revision FROM billium_settlement_intent')));
        self::assertSame(1, $this->rows('billium_allocation_receipt', "kind='settlement_intent'"));
    }

    public function test_cross_account_target_refuses_without_intent_or_receipt(): void
    {
        $this->obligation('foreign', account: 'account-b');
        try {
            $this->ledger()->declareSettlementIntent(new DeclareSettlementIntent('bad', $this->account(), 'business', 'RUB', ['foreign']));
            self::fail('cross account');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ReferenceUnknown, $e->problem);
        }
        self::assertSame(0, $this->rows('billium_settlement_intent'));
        self::assertSame(0, $this->rows('billium_allocation_receipt', "kind='settlement_intent'"));
    }
}
