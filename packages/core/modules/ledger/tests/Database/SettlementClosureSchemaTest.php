<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Database;

use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use PDOException;
use PHPUnit\Framework\Attributes\Group;

/** FA02 storage proof only; the collection owner's typed proof validation is tested with the allocation routines. */
#[Group('pg')]
final class SettlementClosureSchemaTest extends LedgerTestCase
{
    public function test_guarded_closure_replays_one_scoped_immutable_receipt_and_preserves_targets(): void
    {
        $o = $this->obligation();
        $this->ledger()->declareSettlementIntent(new DeclareSettlementIntent('intent', $this->account(), 'intent', 'RUB', ['obligation-a']));
        $targets = $this->table('SELECT * FROM billium_settlement_target ORDER BY position');
        $args = [...$this->scopeArguments(), 1, 'closure', 1, 'step', 'not_dispatched', self::digest(), 'proof', 'cancel', 'closure-receipt'];
        $first = $this->routine('close_settlement_intent_no_effect', $args);
        $replay = $this->routine('close_settlement_intent_no_effect', $args);
        self::assertSame($first['body'], $replay['body']);
        self::assertTrue($replay['replayed']);
        self::assertSame(1, $this->rows('billium_settlement_intent_closure'));
        self::assertSame(2, self::integer($this->value('SELECT revision FROM billium_settlement_intent')));
        self::assertSame($targets, $this->table('SELECT * FROM billium_settlement_target ORDER BY position'));
        foreach ([2 => 'live', 3 => 'foreign-account', 5 => 999, 6 => 'changed-identity', 7 => 2, 10 => self::digest('b')] as $index => $changed) {
            $conflict = $args;
            $conflict[$index] = $changed;
            try {
                $this->routine('close_settlement_intent_no_effect', array_values($conflict));
                self::fail('scope or proof conflict');
            } catch (PDOException $e) {
                self::assertSame('P0001', ($e->errorInfo[0] ?? null));
            }
        }
        $owner = TestEnvironment::pdo(owner: true);
        $runtime = TestEnvironment::pdo();
        self::assertSame('42501', self::sqlState($runtime, 'INSERT INTO billium_settlement_intent_closure DEFAULT VALUES'));
        foreach (['settlement_intent_closure', 'settlement_target'] as $t) {
            self::assertSame('55000', self::sqlState($owner, 'UPDATE billium_'.$t.' SET installation_ref=installation_ref'));
            self::assertSame('55000', self::sqlState($owner, 'DELETE FROM billium_'.$t));
        }
        self::assertSame('55000', self::sqlState($owner, "UPDATE billium_settlement_intent SET revision=revision+1,status='open'"));
        $credit = $this->credit();
        try {
            $this->allocate($o, null, $credit, 1, intent: 1);
            self::fail('closed intent cannot allocate');
        } catch (PDOException $e) {
            self::assertStringContainsString('billium.reference.unknown', $e->getMessage());
        }
    }

    public function test_stale_missing_foreign_and_applied_closure_refuse_and_root_rollback_is_atomic(): void
    {
        $this->obligation();
        $this->ledger()->declareSettlementIntent(new DeclareSettlementIntent('intent', $this->account(), 'intent', 'RUB', ['obligation-a']));
        $args = [...$this->scopeArguments(), 1, 'closure', 1, 'step', 'not_admitted', self::digest(), 'proof', 'cancel', 'closure-receipt'];
        foreach ([7 => 2, 8 => null, 9 => 'timeout', 10 => null, 11 => ''] as $index => $change) {
            $bad = $args;
            $bad[$index] = $change;
            try {
                $this->routine('close_settlement_intent_no_effect', array_values($bad));
                self::fail('invalid closure');
            } catch (PDOException $e) {
                self::assertSame('P0001', ($e->errorInfo[0] ?? null));
            }
        }
        $runtime = TestEnvironment::pdo();
        $runtime->beginTransaction();
        $this->routine('close_settlement_intent_no_effect', $args, $runtime);
        $runtime->rollBack();
        self::assertSame(0, $this->rows('billium_settlement_intent_closure'));
        self::assertSame(1, self::integer($this->value('SELECT revision FROM billium_settlement_intent')));
        $this->payment(intent: 1);
        try {
            $this->routine('close_settlement_intent_no_effect', $args);
            self::fail('payment prevents no-effect closure');
        } catch (PDOException $e) {
            self::assertStringContainsString('billium.idempotency.conflict', $e->getMessage());
        }
    }

    public function test_guarded_routines_have_fixed_search_path_no_public_execute_and_one_owner(): void
    {
        $rows = $this->table("SELECT p.proname,p.prosecdef,p.proconfig,p.proowner=(SELECT relowner FROM pg_class WHERE oid='billium_allocation'::regclass) AS same_owner FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public' AND p.proname IN ('billium_close_settlement_intent_no_effect','billium_allocate','billium_record_obligation','billium_lock_settlement_intent')");
        self::assertCount(4, $rows);
        foreach ($rows as $row) {
            self::assertTrue($row['prosecdef']);
            self::assertTrue($row['same_owner']);
            self::assertStringContainsString('search_path=pg_catalog, pg_temp', self::text($row['proconfig']));
        }
        self::assertSame(0, $this->rows('pg_proc p', "p.pronamespace='public'::regnamespace AND p.proname LIKE 'billium_%' AND p.prosecdef AND EXISTS(SELECT FROM aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a WHERE a.grantee=0 AND a.privilege_type='EXECUTE')"));
    }
}
