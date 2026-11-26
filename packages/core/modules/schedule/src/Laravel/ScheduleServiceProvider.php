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
use Billium\Schedule\Internal\OccurrenceConformance;
use Billium\Schedule\Internal\Participants\CanonicalCorrectionBasis;
use Billium\Schedule\Internal\Participants\DatabaseCorrectionParticipants;
use Billium\Schedule\Internal\Participants\DatabaseOccurrenceParticipants;
use Billium\Schedule\Internal\Services\DatabaseOccurrenceService;
use Billium\Schedule\Internal\Services\DatabaseScheduleQueries;
use Billium\Schedule\Internal\SystemClock;
use Billium\Schedule\Internal\UnboundAgreementRevisionGuard;
use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Laravel service provider registering the Billium Schedule module runtime bindings.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ScheduleServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->bind('billium.conformance.schedule', OccurrenceConformance::class);
        $this->app->singleton(ScheduleLockRequests::class);
        $this->app->alias(ScheduleLockRequests::class, OccurrenceLocks::class);
        $this->app->bind(OccurrenceService::class, DatabaseOccurrenceService::class);
        $this->app->bind(OccurrenceParticipants::class, DatabaseOccurrenceParticipants::class);
        $this->app->bind(CorrectionParticipants::class, DatabaseCorrectionParticipants::class);
        $this->app->bind(ScheduleQueries::class, DatabaseScheduleQueries::class);
        $this->app->bind(IntervalResolver::class, CalendarIntervalResolver::class);
        $this->app->bind(AgreementRevisionCommit::class, DatabaseAgreementRevisionCommit::class);
        $this->app->bind(CommercialPeriods::class, CommercialPeriodExport::class);
        $this->app->bindIf(AgreementRevisionGuard::class, UnboundAgreementRevisionGuard::class);
        $this->app->bindIf(CorrectionBasisPolicy::class, CanonicalCorrectionBasis::class);
        $this->app->bindIf(Clock::class, SystemClock::class);
    }

    public function boot(): void {}
}
