<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Import;

use Billium\Contracts\Data\Money;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\Receipt;
use Billium\Platform\Data\Import\ImportCreditNoteWrite;
use Billium\Platform\Data\Import\ImportCurrencyConservation;
use Billium\Platform\Data\Import\ImportObligationWrite;
use Billium\Platform\Data\Import\ImportObservationOutcome;
use Billium\Platform\Data\Import\ImportSettlementIntentWrite;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * SPI port bridging platform-owned import orchestration to ledger participants, observation reduction and read-only queries.
 *
 * Keeps module dependency direction strictly ledger -> platform: platform never imports Billium\Ledger\* or touches ledger tables.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ImportMonetaryPort
{
    public function recordObligation(TransactionContext $context, ImportObligationWrite $command): Receipt;

    public function declareSettlementIntent(TransactionContext $context, ImportSettlementIntentWrite $command): Receipt;

    public function adjustDraftObligation(
        TransactionContext $context,
        string $commandKey,
        string $sellerRef,
        string $environment,
        string $billingAccountRef,
        string $adjustmentRef,
        string $obligationRef,
        Money $delta,
        string $causeRef,
    ): Receipt;

    public function issueCreditNote(TransactionContext $context, ImportCreditNoteWrite $command): Receipt;

    public function derivePositiveCorrectionBasis(string $originalBasisRef, string $correctionRef, int $revision): string;

    /**
     * Drains queued custody deliveries on the partition and retries unblocked parked dependencies,
     * executing strictly ONE economic event per independent observation-owner root transaction.
     *
     * @return int number of observation-owner steps processed
     */
    public function reduceObservationPartition(?int $partitionId = null): int;

    /**
     * Queries the observation owner's committed state and AllocationReceipt by deterministic source identity.
     */
    public function findObservationOutcome(
        string $sourceAuthority,
        string $operationRef,
        string $operationKind,
        int $operationRevision,
    ): ImportObservationOutcome;

    /**
     * Advisory read-only refund capacity check against an already projected original charge.
     *
     * @return array{exists: bool, currency?: string, providerAccountRef?: string, normalCapacityMinor?: int, allowed?: bool, financialHold?: bool}
     */
    public function inspectOriginalChargeForRefund(string $sourceAuthority, string $originalPaymentRef, Money $refundMoney): array;

    /**
     * Read-only lookup of a projected obligation for linked correction validation.
     *
     * @return array{exists: bool, billingAccountRef?: string, customerRef?: string, componentRef?: string, basisRef?: string, currency?: string, dueMinor?: int, openMinor?: int, status?: string, invoiceRef?: string|null, termsDigest?: string, priceVersionRef?: string|null, taxSnapshotRef?: string|null}
     */
    public function inspectObligation(string $obligationRef): array;

    /**
     * Read-only scoped conservation snapshot per currency for the current authenticated seller/environment/installation.
     *
     * @return array<string, ImportCurrencyConservation>
     */
    public function readScopedConservation(): array;
}
