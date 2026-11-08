<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal;

use Billium\Core\Contracts\CallerContext;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Contracts\ObservationApplier;
use Billium\Ledger\Internal\Console\DrainParkedCommand;
use Billium\Ledger\Internal\Console\ReconcileRefundsCommand;
use Billium\Ledger\Internal\Observation\ObservationHandler;
use Billium\Ledger\Internal\Observation\ObservationIntake;
use Billium\Ledger\Internal\Participants\DatabaseLedgerParticipants;
use Billium\Ledger\Internal\Queries\DatabaseLedgerQueries;
use Billium\Ledger\Internal\Repositories\LedgerPrivileges;
use Billium\Ledger\Internal\Snapshot\LedgerSnapshotSource;
use Billium\Platform\Contracts\CustodyHandler;
use Billium\Platform\Contracts\FeedRecoveryQueries;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

final class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Ledger::class, LedgerFacade::class);
        $this->app->bind(LedgerParticipants::class, DatabaseLedgerParticipants::class);
        $this->app->bind(ObservationApplier::class, ObservationIntake::class);
        $this->app->bind(LedgerQueries::class, DatabaseLedgerQueries::class);
        foreach (LedgerSnapshotSource::KEYS as $key) {
            $service = 'billium.snapshot.'.$key;
            $this->app->bind($service, fn (): LedgerSnapshotSource => new LedgerSnapshotSource(
                $this->app->make(DatabaseManager::class), $this->app->make(CallerContext::class), $key, $this->app->make(FeedRecoveryQueries::class),
            ));
            $this->app->tag($service, 'billium.snapshot_sources');
        }
        $this->app->when(ObservationIntake::class)->needs(CustodyHandler::class)->give(ObservationHandler::class);
        $this->app->singleton(LedgerPrivileges::class, static function (): LedgerPrivileges {
            $name = config('billium.connection') ?? config('database.default');

            return new LedgerPrivileges(is_string($name) ? $name : 'default');
        });
    }

    public function boot(): void
    {
        // Capture runtime before migrate --database selects the migration owner.
        $this->app->make(LedgerPrivileges::class);
        if ($this->app->runningInConsole()) {
            $this->commands([DrainParkedCommand::class, ReconcileRefundsCommand::class]);
        }
    }
}
