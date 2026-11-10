<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests\Feature;

use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\LockRequest;
use Billium\Core\Enums\LockRank;
use Billium\Core\Tests\TestEnvironment;
use Billium\Schedule\Contracts\CorrectionParticipants;
use Billium\Schedule\Contracts\OccurrenceLocks;
use Billium\Schedule\Contracts\ScheduleQueries;
use Billium\Schedule\Data\Commands\RegisterCorrectionChild;
use Billium\Schedule\Data\CorrectionBasis;
use Billium\Schedule\Data\CorrectionReceipt;
use Billium\Schedule\Tests\OccurrenceTestCase;
use DateTimeImmutable;
use LogicException;

final class OccurrenceCorrectionParticipantTest extends OccurrenceTestCase
{
    /** @param array<string, mixed> $overrides */
    private function command(string $parent, array $overrides = []): RegisterCorrectionChild
    {
        return new RegisterCorrectionChild(...[
            'sellerRef' => 'seller-a', 'environment' => 'test', 'parentOccurrenceRef' => $parent,
            'originalPeriodStart' => new DateTimeImmutable('2024-01-31Z'), 'originalPeriodEnd' => new DateTimeImmutable('2024-02-29Z'),
            'correctionRevision' => 1, 'predecessorRevision' => null, 'predecessorRatedBasisRef' => 'rated:original',
            'sourceFrontierDigest' => 'frontier:1', 'expectedHeadRevision' => 0, 'expectedHeadFrontierDigest' => null,
            'taxCorrectionPolicyRef' => null, 'taxCorrectionPolicyVersion' => null, 'reasonCode' => 'late_usage',
            'obligationBasisRef' => CorrectionBasis::derive(parentOccurrenceRef: $parent, correctionRevision: 1), ...$overrides]);
    }

    private function register(RegisterCorrectionChild $command, bool $lock = true): CorrectionReceipt
    {
        $receipt = null;
        $this->runner()->run(spec: $this->spec(kind: 'schedule.correction.test', modules: ['platform', 'ledger', 'rating', 'schedule']),
            body: function (TransactionContext $ctx) use ($command, $lock, &$receipt): CorrectionReceipt {
                if ($lock) {
                    $this->application()->make(OccurrenceLocks::class)->acquire(context: $ctx, occurrenceRefs: [$command->parentOccurrenceRef]);
                    $ctx->lock(new LockRequest(rank: LockRank::Obligation,
                        table: 'billium_obligation', keys: [999999], guardedRoutine: 'billium_lock_obligation'));
                }

                return $receipt = $this->application()->make(CorrectionParticipants::class)->registerCorrectionChild(context: $ctx, command: $command);
            })->orThrow();

        return $receipt ?? throw new LogicException('Missing correction receipt');
    }

    public function test_child_lineage_is_idempotent_and_parent_remains_byte_identical(): void
    {
        $this->createSchedule();
        $ref = self::text($this->occurrences()->ensureOccurrence($this->ensure())->body()['occurrenceRef']);
        $this->advance($ref);
        $before = $this->row('SELECT * FROM billium_schedule_billing_occurrence');
        $events = $this->rows('billium_schedule_occurrence_outbox');
        $first = $this->register($this->command($ref));
        self::assertTrue($first->created);
        self::assertFalse($this->register($this->command($ref))->created);
        $this->refusal(fn () => $this->register($this->command(parent: $ref, overrides: ['reasonCode' => 'changed'])), 'correction.conflict');
        $next = ['correctionRevision' => 2, 'predecessorRevision' => 1, 'sourceFrontierDigest' => 'frontier:2',
            'predecessorRatedBasisRef' => $first->correctionRef,
            'obligationBasisRef' => CorrectionBasis::derive(parentOccurrenceRef: $ref, correctionRevision: 2)];
        $this->refusal(fn () => $this->register($this->command(parent: $ref, overrides: $next)), 'correction.frontier_stale');
        self::assertTrue($this->register($this->command(parent: $ref, overrides: [...$next, 'expectedHeadRevision' => 1, 'expectedHeadFrontierDigest' => 'frontier:1']))->created);
        self::assertSame($before, $this->row('SELECT * FROM billium_schedule_billing_occurrence'));
        self::assertSame(1, $this->rows('billium_schedule_billing_occurrence'));
        self::assertSame(2, $this->rows('billium_schedule_billing_correction'));
        $due = $this->application()->make(ScheduleQueries::class)->dueSchedules(sellerRef: 'seller-a', asOf: new DateTimeImmutable('2024-03-01Z'));
        self::assertCount(1, $due->schedules);
        self::assertSame('schedule-a', $due->schedules[0]['schedule_ref']);
        self::assertSame($events, $this->rows('billium_schedule_occurrence_outbox'));
        self::assertSame(['revision' => 2, 'frontierDigest' => 'frontier:2'],
            $this->application()->make(ScheduleQueries::class)->correctionHead(sellerRef: 'seller-a', parentOccurrenceRef: $ref));
        $owner = TestEnvironment::pdo(owner: true);
        self::assertSame('55000', self::sqlState(pdo: $owner, sql: 'UPDATE billium_schedule_billing_correction SET reason_code=reason_code'));
    }

    public function test_parent_must_be_obligated_and_the_original_period_must_match(): void
    {
        $this->createSchedule();
        $ref = self::text($this->occurrences()->ensureOccurrence($this->ensure())->body()['occurrenceRef']);
        $this->refusal(fn () => $this->register($this->command($ref)), 'correction.parent_invalid');
        $this->advance($ref);
        $this->refusal(fn () => $this->register($this->command(parent: $ref, overrides: ['originalPeriodEnd' => new DateTimeImmutable('2024-03-01Z')])), 'correction.parent_invalid');
        $this->refusal(fn () => $this->register($this->command(parent: $ref, overrides: ['obligationBasisRef' => 'arbitrary-basis'])), 'correction.conflict');
        self::assertSame(0, $this->rows('billium_schedule_billing_correction'));
        self::assertSame(0, $this->rows('billium_schedule_correction_head'));
    }

    public function test_missing_parent_lock_and_stale_context_refuse_before_any_child_write(): void
    {
        $this->createSchedule();
        $ref = self::text($this->occurrences()->ensureOccurrence($this->ensure())->body()['occurrenceRef']);
        $this->advance($ref);
        try {
            $this->register(command: $this->command($ref), lock: false);
            self::fail('Missing lock accepted');
        } catch (LogicException $e) {
            self::assertStringContainsString('preplanned', $e->getMessage());
        }
        $stale = null;
        $this->runner()->run(spec: $this->spec(kind: 'schedule.context.test', modules: ['platform', 'ledger', 'rating', 'schedule']),
            body: function (TransactionContext $ctx) use (&$stale) {
                $stale = $ctx;

                return self::receipt();
            });
        self::assertNotNull($stale);
        try {
            $this->application()->make(CorrectionParticipants::class)->registerCorrectionChild(context: $stale, command: $this->command($ref));
            self::fail('Stale context accepted');
        } catch (LogicException) {
            self::assertSame(0, $this->rows('billium_schedule_billing_correction'));
        }
    }
}
