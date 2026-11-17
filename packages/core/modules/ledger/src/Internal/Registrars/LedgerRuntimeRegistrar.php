<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Registrars;

use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Ledger\Contracts\CorrectionCreditParticipants;
use Billium\Ledger\Contracts\CorrectionLocks;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Contracts\ObservationApplier;
use Billium\Ledger\Contracts\ObservationCheckpointQueries;
use Billium\Ledger\Contracts\QualifiedObservationApplier;
use Billium\Ledger\Contracts\SettlementIntentLocks;
use Billium\Ledger\Internal\LedgerFacade;
use Billium\Ledger\Internal\Observation\ObservationHandler;
use Billium\Ledger\Internal\Observation\ObservationIntake;
use Billium\Ledger\Internal\Participants\DatabaseCorrectionCreditParticipants;
use Billium\Ledger\Internal\Participants\DatabaseCorrectionLocks;
use Billium\Ledger\Internal\Participants\DatabaseLedgerParticipants;
use Billium\Ledger\Internal\Queries\DatabaseLedgerQueries;
use Billium\Ledger\Internal\Queries\DatabaseObservationCheckpointQueries;
use Billium\Ledger\Internal\Queries\DatabaseSettlementIntentLocks;
use Billium\Ledger\Internal\Repositories\LedgerPrivileges;
use Billium\Ledger\Internal\Snapshot\LedgerSnapshotSource;
use Billium\Platform\Contracts\Feed\FeedRecoveryQueries;
use Billium\Platform\Contracts\Inbox\CustodyHandler;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Override;
use Packman\Attributes\Internal;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Internal]
final class LedgerRuntimeRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->bind(Ledger::class, LedgerFacade::class);
        $app->bind(CorrectionLocks::class, DatabaseCorrectionLocks::class);
        $app->bind(CorrectionCreditParticipants::class, DatabaseCorrectionCreditParticipants::class);
        $app->bind(LedgerParticipants::class, DatabaseLedgerParticipants::class);
        $app->bind(ObservationApplier::class, ObservationIntake::class);
        $app->bind(QualifiedObservationApplier::class, ObservationIntake::class);
        $app->bind(LedgerQueries::class, DatabaseLedgerQueries::class);
        $app->bind(ObservationCheckpointQueries::class, DatabaseObservationCheckpointQueries::class);
        $app->bind(SettlementIntentLocks::class, DatabaseSettlementIntentLocks::class);

        foreach (LedgerSnapshotSource::KEYS as $key) {
            $service = 'billium.snapshot.'.$key;
            $app->bind($service, static fn (Application $app): LedgerSnapshotSource => new LedgerSnapshotSource(
                $app->make(DatabaseManager::class),
                $app->make(CallerContext::class),
                $key,
                $app->make(FeedRecoveryQueries::class),
            ));
            $app->tag($service, 'billium.snapshot_sources');
        }

        $app->when(ObservationIntake::class)->needs(CustodyHandler::class)->give(ObservationHandler::class);

        $app->singleton(LedgerPrivileges::class, static function (Application $app): LedgerPrivileges {
            $config = $app->bound(Repository::class) ? $app->make(Repository::class) : null;
            $connection = $config !== null ? ($config->get('billium.connection') ?? $config->get('database.default')) : null;

            return new LedgerPrivileges(is_string($connection) ? $connection : 'default');
        });
    }

    #[Override]
    public function boot(Application $app): void
    {
        // Capture runtime before migrate --database selects the migration owner.
        $app->make(LedgerPrivileges::class);
    }
}
