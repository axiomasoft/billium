<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Enums\FinancialMode;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * ledger.feed.bootstrap in a genuine recovery session (D54, PF-14): after an ownership change the session epoch is in
 * recovery and this process holds a Recovery-only host token. Every root of the bootstrap — staging, items, adoption,
 * the tail drain and the status read — keeps Recovery admission, returns the adopted status, and a repeat is
 * idempotent, with an empty or a populated held tail.
 */
#[Group('PF-14')]
final class BootstrapRecoverySessionTest extends ObservationTestCase
{
    public function test_a_recovery_bootstrap_drains_the_held_tail_and_repeats_idempotently(): void
    {
        $this->debt('o1', 5000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, sequence: 1));
        $this->recoverySession();
        $restored = ObservationFactory::charge('op-1', 2, 2500, sequence: 2, fields: ['sourceLineage' => 'L2']);
        $tail = ObservationFactory::charge('op-1', 3, 3000, sequence: 3, fields: ['sourceLineage' => 'L2']);
        self::assertSame(ProblemCode::RecoveryRequired->value, $this->attempt($restored));
        self::assertSame(ProblemCode::RecoveryRequired->value, $this->attempt($tail));
        $snapshot = $this->snapshot('boot-recovery', 'L2', 2, [$restored]);

        $status = $this->bootstrapper()->execute($snapshot, FinancialMode::Recovery);

        self::assertSame(['adopted', 1, 0], [$status->status, $status->applied, $status->staged]);
        self::assertSame(['active', 'L2', 3], [$this->partition()['state'], $this->partition()['source_lineage'], self::integer($this->partition()['accounted_sequence'])]);
        self::assertSame('applied', $this->inboxStatus(self::eventId($tail)), 'the held tail drained in Recovery roots');
        self::assertSame(3000, $this->allocated('o1'));

        $again = $this->bootstrapper()->execute($snapshot, FinancialMode::Recovery);

        self::assertSame([$status->sessionId, 'adopted', $status->newPartitionId], [$again->sessionId, $again->status, $again->newPartitionId]);
        self::assertSame(3, $this->rows('billium_allocation'));
        self::assertSame(3000, $this->allocated('o1'));
    }

    public function test_a_recovery_bootstrap_with_an_empty_tail_returns_its_status_and_repeats(): void
    {
        $this->debt('o1', 5000);
        $this->intent('intent-a', ['o1']);
        $this->recoverySession();
        $snapshot = $this->snapshot('boot-empty', 'L1', 4, [ObservationFactory::charge('op-9', 1, 700, sequence: 4, fields: ['partitionRef' => 'part-2'])], partition: 'part-2');

        $status = $this->bootstrapper()->execute($snapshot, FinancialMode::Recovery);
        $again = $this->bootstrapper()->execute($snapshot, FinancialMode::Recovery);

        self::assertSame(['adopted', 1, 1], [$status->status, $status->items, $status->applied]);
        self::assertSame([$status->sessionId, 'adopted', $status->newPartitionId], [$again->sessionId, $again->status, $again->newPartitionId]);
        self::assertSame(['active', 4], [$this->partition('part-2')['state'], self::integer($this->partition('part-2')['accounted_sequence'])]);
        self::assertSame(700, $this->allocated('o1'));
        self::assertSame(1, $this->rows('billium_allocation'));
    }

    /** A new owner epoch without a seal supersedes the active one into recovery; this process may act in Recovery only. */
    private function recoverySession(): void
    {
        $this->installation()->openSession('open-2', 'owner-1', 1, 1, 1, 'evidence:open-2');
        $this->tokens->set(FixedSessionAdmission::token(self::INSTALLATION, modes: [FinancialMode::Recovery]));
        self::assertSame('recovery', $this->value('SELECT state FROM billium_financial_session ORDER BY epoch DESC LIMIT 1'));
    }
}
