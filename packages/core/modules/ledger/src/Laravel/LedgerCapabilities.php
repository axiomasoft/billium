<?php

declare(strict_types=1);

namespace Billium\Ledger\Laravel;

use Billium\Ledger\Contracts\CorrectionCreditParticipants;
use Billium\Ledger\Contracts\CorrectionLocks;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Contracts\ObservationApplier;
use Billium\Ledger\Internal\LedgerFacade;
use Billium\Ledger\Internal\Observation\ObservationHandler;
use Billium\Ledger\Internal\Observation\ObservationIntake;
use Billium\Ledger\Internal\Participants\DatabaseCorrectionCreditParticipants;
use Billium\Ledger\Internal\Participants\DatabaseCorrectionLocks;
use Billium\Ledger\Internal\Participants\DatabaseLedgerParticipants;
use Billium\Ledger\Internal\Queries\DatabaseLedgerQueries;
use Billium\Ledger\Internal\Snapshot\AllocationsSnapshotSource;
use Billium\Ledger\Internal\Snapshot\DiscrepanciesSnapshotSource;
use Billium\Ledger\Internal\Snapshot\InvoicesSnapshotSource;
use Billium\Ledger\Internal\Snapshot\ObligationsSnapshotSource;
use Billium\Ledger\Internal\Snapshot\PaymentsSnapshotSource;
use Billium\Platform\Contracts\Feed\SnapshotSource;
use Billium\Platform\Contracts\Inbox\BootstrapItemHandler;
use Billium\Platform\Contracts\Inbox\CustodyHandler;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Capability and slot declarations provided by the Billium Ledger module for Core plugin wiring.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class LedgerCapabilities
{
    /** @return array<class-string, class-string> */
    public static function map(): array
    {
        return [
            CorrectionCreditParticipants::class => DatabaseCorrectionCreditParticipants::class,
            CorrectionLocks::class => DatabaseCorrectionLocks::class,
            Ledger::class => LedgerFacade::class,
            LedgerParticipants::class => DatabaseLedgerParticipants::class,
            LedgerQueries::class => DatabaseLedgerQueries::class,
            ObservationApplier::class => ObservationIntake::class,
        ];
    }

    /** @return list<array{slot: class-string, id: string, implementation: class-string, priority: int, key: string}> */
    public static function slots(): array
    {
        return [
            [
                'slot' => CustodyHandler::class,
                'id' => 'ledger.payment.observation',
                'implementation' => ObservationHandler::class,
                'priority' => 0,
                'key' => 'payment.observation',
            ],
            [
                'slot' => BootstrapItemHandler::class,
                'id' => 'ledger.payment.observation',
                'implementation' => ObservationHandler::class,
                'priority' => 0,
                'key' => 'payment.observation',
            ],
            [
                'slot' => SnapshotSource::class,
                'id' => 'ledger.obligations',
                'implementation' => ObligationsSnapshotSource::class,
                'priority' => 10,
                'key' => 'ledger.obligations',
            ],
            [
                'slot' => SnapshotSource::class,
                'id' => 'ledger.invoices',
                'implementation' => InvoicesSnapshotSource::class,
                'priority' => 20,
                'key' => 'ledger.invoices',
            ],
            [
                'slot' => SnapshotSource::class,
                'id' => 'ledger.allocations',
                'implementation' => AllocationsSnapshotSource::class,
                'priority' => 30,
                'key' => 'ledger.allocations',
            ],
            [
                'slot' => SnapshotSource::class,
                'id' => 'ledger.payments',
                'implementation' => PaymentsSnapshotSource::class,
                'priority' => 40,
                'key' => 'ledger.payments',
            ],
            [
                'slot' => SnapshotSource::class,
                'id' => 'ledger.discrepancies',
                'implementation' => DiscrepanciesSnapshotSource::class,
                'priority' => 50,
                'key' => 'ledger.discrepancies',
            ],
        ];
    }
}
