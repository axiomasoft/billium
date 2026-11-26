<?php

declare(strict_types=1);

namespace Billium\Schedule\Laravel;

use Billium\Schedule\Contracts\AgreementRevisionCommit;
use Billium\Schedule\Contracts\AgreementRevisionGuard;
use Billium\Schedule\Contracts\Clock;
use Billium\Schedule\Contracts\CommercialPeriods;
use Billium\Schedule\Contracts\CorrectionBasisPolicy;
use Billium\Schedule\Contracts\CorrectionParticipants;
use Billium\Schedule\Contracts\IntervalResolver;
use Billium\Schedule\Contracts\OccurrenceLocks;
use Billium\Schedule\Contracts\OccurrenceParticipants;
use Billium\Schedule\Contracts\OccurrenceService;
use Billium\Schedule\Contracts\ScheduleQueries;
use Billium\Schedule\Internal\CalendarIntervalResolver;
use Billium\Schedule\Internal\CommercialPeriodExport;
use Billium\Schedule\Internal\DatabaseAgreementRevisionCommit;
use Billium\Schedule\Internal\Locks\ScheduleLockRequests;
use Billium\Schedule\Internal\Participants\CanonicalCorrectionBasis;
use Billium\Schedule\Internal\Participants\DatabaseCorrectionParticipants;
use Billium\Schedule\Internal\Participants\DatabaseOccurrenceParticipants;
use Billium\Schedule\Internal\Services\DatabaseOccurrenceService;
use Billium\Schedule\Internal\Services\DatabaseScheduleQueries;
use Billium\Schedule\Internal\SystemClock;
use Billium\Schedule\Internal\UnboundAgreementRevisionGuard;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Capability declarations provided by the Billium Schedule module for Core plugin wiring.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ScheduleCapabilities
{
    /** @return array<class-string, class-string> */
    public static function map(): array
    {
        return [
            OccurrenceLocks::class => ScheduleLockRequests::class,
            OccurrenceService::class => DatabaseOccurrenceService::class,
            OccurrenceParticipants::class => DatabaseOccurrenceParticipants::class,
            CorrectionParticipants::class => DatabaseCorrectionParticipants::class,
            ScheduleQueries::class => DatabaseScheduleQueries::class,
            IntervalResolver::class => CalendarIntervalResolver::class,
            AgreementRevisionCommit::class => DatabaseAgreementRevisionCommit::class,
            CommercialPeriods::class => CommercialPeriodExport::class,
            AgreementRevisionGuard::class => UnboundAgreementRevisionGuard::class,
            CorrectionBasisPolicy::class => CanonicalCorrectionBasis::class,
            Clock::class => SystemClock::class,
        ];
    }
}
