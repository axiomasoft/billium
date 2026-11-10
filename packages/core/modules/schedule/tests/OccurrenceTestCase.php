<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\Receipt;
use Billium\Core\Tests\Support\FixtureArguments;
use Billium\Schedule\Contracts\OccurrenceLocks;
use Billium\Schedule\Contracts\OccurrenceParticipants;
use Billium\Schedule\Contracts\OccurrenceService;
use Billium\Schedule\Data\Commands\AdvanceOccurrence;
use Billium\Schedule\Data\Commands\ApplyScheduleRevision;
use Billium\Schedule\Data\Commands\EnsureOccurrence;
use Billium\Schedule\Enums\OccurrencePurpose;
use Billium\Schedule\Enums\OccurrenceState;
use Billium\Schedule\Tests\Support\Terms;
use DateTimeImmutable;

abstract class OccurrenceTestCase extends ScheduleTestCase
{
    protected function occurrences(): OccurrenceService
    {
        return $this->application()->make(OccurrenceService::class);
    }

    /** @param array<string, mixed> $overrides */
    protected function revision(array $overrides = []): ApplyScheduleRevision
    {
        return FixtureArguments::construct(class: ApplyScheduleRevision::class, arguments: ['sellerRef' => 'seller-a', 'environment' => 'test', 'scheduleRef' => 'schedule-a', 'expectedRevision' => 0, 'expectedRowVersion' => 0, 'newRevision' => 1, 'terms' => Terms::make(['purpose' => 'recurring_charge']), 'effectiveFrom' => new DateTimeImmutable('2024-01-01Z'), 'inputsDigest' => 'sha256:'.str_repeat('a', 64), 'commandKey' => 'revision-1', ...$overrides]);
    }

    protected function createSchedule(): void
    {
        $this->occurrences()->applyScheduleRevision($this->revision());
    }

    /** @param array<string, mixed> $overrides */
    protected function ensure(array $overrides = []): EnsureOccurrence
    {
        return FixtureArguments::construct(class: EnsureOccurrence::class, arguments: ['sellerRef' => 'seller-a', 'environment' => 'test', 'componentRef' => 'component-a', 'purpose' => OccurrencePurpose::RecurringCharge, 'periodStart' => new DateTimeImmutable('2024-01-31Z'), 'periodEnd' => new DateTimeImmutable('2024-02-29Z'), 'scheduleRef' => 'schedule-a', 'scheduleRevision' => 1, 'inputsDigest' => 'sha256:'.str_repeat('a', 64), 'commandKey' => 'ensure-1', ...$overrides]);
    }

    protected function advance(string $ref, OccurrenceState $state = OccurrenceState::Obligated): void
    {
        $this->runner()->run(spec: $this->spec(kind: 'schedule.advance.test', modules: ['platform', 'ledger', 'rating', 'schedule']), body: function (TransactionContext $ctx) use ($ref, $state): Receipt {
            $this->application()->make(OccurrenceLocks::class)->acquire(context: $ctx, occurrenceRefs: [$ref]);

            return $this->application()->make(OccurrenceParticipants::class)->advanceOccurrence(context: $ctx, command: new AdvanceOccurrence(sellerRef: 'seller-a', environment: 'test', occurrenceRef: $ref, to: $state, ratedBasisRef: 'rated:original'));
        })->orThrow();
    }

    protected function refusal(callable $action, string $detail): void
    {
        try {
            $action();
            self::fail('Expected '.$detail);
        } catch (ProblemException $e) {
            self::assertSame('billium.'.$detail, $e->detail);
        }
    }
}
