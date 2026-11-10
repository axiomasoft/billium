<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal;

use Billium\Schedule\Contracts\AgreementRevisionCommit;
use Billium\Schedule\Contracts\AgreementRevisionGuard;
use Billium\Schedule\Contracts\Clock;
use Billium\Schedule\Contracts\CommercialPeriods;
use Billium\Schedule\Contracts\IntervalResolver;
use Illuminate\Support\ServiceProvider;

final class ScheduleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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
