<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal;

use Billium\Schedule\Contracts\AgreementRevisionCommit;
use Billium\Schedule\Contracts\AgreementRevisionGuard;
use Billium\Schedule\Contracts\Clock;
use Billium\Schedule\Contracts\CommercialPeriods;
use Billium\Schedule\Contracts\CorrectionParticipants;
use Billium\Schedule\Contracts\IntervalResolver;
use Billium\Schedule\Contracts\OccurrenceLocks;
use Billium\Schedule\Contracts\OccurrenceParticipants;
use Billium\Schedule\Contracts\OccurrenceService;
use Billium\Schedule\Contracts\ScheduleQueries;
use Billium\Schedule\Internal\Locks\ScheduleLockRequests;
use Billium\Schedule\Internal\Participants\DatabaseCorrectionParticipants;
use Billium\Schedule\Internal\Participants\DatabaseOccurrenceParticipants;
use Billium\Schedule\Internal\Services\DatabaseOccurrenceService;
use Billium\Schedule\Internal\Services\DatabaseScheduleQueries;
use Illuminate\Support\ServiceProvider;

final class ScheduleServiceProvider extends ServiceProvider
{
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
        $this->app->bindIf(Clock::class, SystemClock::class);
    }

    public function boot(): void
    {
        // D55: load paths only; host install/upgrade applies SQL and enrolls module state explicitly.
        $this->loadMigrationsFrom(__DIR__.'/../../../../database/migrations/schedule');
    }
}
