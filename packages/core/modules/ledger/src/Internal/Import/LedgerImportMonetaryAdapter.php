<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Import;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\Receipt;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Data\Invoices\CreditNoteLine;
use Billium\Ledger\Data\Invoices\CreditNoteMode;
use Billium\Ledger\Data\Invoices\IssueCreditNote;
use Billium\Ledger\Data\Obligations\AdjustObligation;
use Billium\Ledger\Data\Obligations\CorrectionBasis;
use Billium\Ledger\Data\Obligations\ObligationDuePolicy;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Ledger\Internal\Observation\ParkedDrainer;
use Billium\Ledger\Internal\Observation\PaymentProjection;
use Billium\Platform\Contracts\Import\ImportMonetaryPort;
use Billium\Platform\Data\Import\ImportCreditNoteWrite;
use Billium\Platform\Data\Import\ImportCurrencyConservation;
use Billium\Platform\Data\Import\ImportObligationWrite;
use Billium\Platform\Data\Import\ImportObservationOutcome;
use Billium\Platform\Data\Import\ImportSettlementIntentWrite;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Override;
use Packman\Attributes\Internal;

/**
 * Ledger-owned adapter implementing Platform's ImportMonetaryPort over typed LedgerParticipants, ParkedDrainer and LedgerQueries.
 */
#[Internal]
final readonly class LedgerImportMonetaryAdapter implements ImportMonetaryPort
{
    /**
     * @param  Closure(): LedgerParticipants  $participants
     * @param  Closure(): ParkedDrainer  $drainer
     * @param  Closure(): LedgerQueries  $queries
     * @param  Closure(): CallerContext  $callers
     */
    public function __construct(
        private Closure $participants,
        private Closure $drainer,
        private Closure $queries,
        private Closure $callers,
        private DatabaseManager $databases,
        private BilliumOptions $options,
    ) {}

    #[Override]
    public function recordObligation(TransactionContext $context, ImportObligationWrite $command): Receipt
    {
        return ($this->participants)()->recordObligation($context, new RecordObligation(
            commandKey: $command->commandKey,
            scope: new BillingAccountScope(
                sellerRef: $command->sellerRef,
                environment: $command->environment,
                billingAccountRef: $command->billingAccountRef,
            ),
            obligationRef: $command->obligationRef,
            basisRef: $command->basisRef,
            customerRef: $command->customerRef,
            componentRef: $command->componentRef,
            money: $command->money,
            termsDigest: $command->termsDigest,
            priceVersionRef: $command->priceVersionRef,
            taxSnapshotRef: $command->taxSnapshotRef,
            duePolicy: new ObligationDuePolicy,
        ));
    }

    #[Override]
    public function declareSettlementIntent(TransactionContext $context, ImportSettlementIntentWrite $command): Receipt
    {
        return ($this->participants)()->declareSettlementIntent($context, new DeclareSettlementIntent(
            commandKey: $command->commandKey,
            scope: new BillingAccountScope(
                sellerRef: $command->sellerRef,
                environment: $command->environment,
                billingAccountRef: $command->billingAccountRef,
            ),
            businessIntentRef: $command->businessIntentRef,
            currency: $command->currency,
            obligationRefs: $command->obligationRefs,
            requestedMinor: $command->requestedMinor,
        ));
    }

    #[Override]
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
    ): Receipt {
        return ($this->participants)()->adjustObligation($context, new AdjustObligation(
            commandKey: $commandKey,
            scope: new BillingAccountScope(
                sellerRef: $sellerRef,
                environment: $environment,
                billingAccountRef: $billingAccountRef,
            ),
            adjustmentRef: $adjustmentRef,
            obligationRef: $obligationRef,
            money: $delta,
            causeRef: $causeRef,
        ));
    }

    #[Override]
    public function issueCreditNote(TransactionContext $context, ImportCreditNoteWrite $command): Receipt
    {
        $lines = [];
        foreach ($command->lines as $line) {
            $lines[] = new CreditNoteLine(
                obligationRef: $line['obligationRef'],
                money: $line['money'],
                originalComponentRef: $line['componentRef'] ?? null,
                originalTaxSnapshotRef: $line['taxSnapshotRef'] ?? null,
            );
        }

        return ($this->participants)()->issueCreditNote($context, new IssueCreditNote(
            commandKey: $command->commandKey,
            scope: new BillingAccountScope(
                sellerRef: $command->sellerRef,
                environment: $command->environment,
                billingAccountRef: $command->billingAccountRef,
            ),
            creditNoteRef: $command->creditNoteRef,
            invoiceRef: $command->invoiceRef,
            mode: CreditNoteMode::from($command->mode),
            lines: $lines,
            reason: $command->reason,
        ));
    }

    #[Override]
    public function derivePositiveCorrectionBasis(string $originalBasisRef, string $correctionRef, int $revision): string
    {
        return CorrectionBasis::derive($originalBasisRef, $correctionRef, $revision);
    }

    #[Override]
    public function reduceObservationPartition(?int $partitionId = null): int
    {
        $drainer = ($this->drainer)();
        $steps = 0;
        if ($partitionId !== null) {
            $steps += count($drainer->drainPartition($partitionId));
        }
        $steps += count($drainer->retryParked());

        return $steps;
    }

    #[Override]
    public function findObservationOutcome(
        string $sourceAuthority,
        string $operationRef,
        string $operationKind,
        int $operationRevision,
    ): ImportObservationOutcome {
        $prefix = $operationKind === 'refund' ? 'refund-detail' : 'observation';
        $expectedReceiptRef = PaymentProjection::receiptRef($prefix, $sourceAuthority, $operationRef, $operationRevision);
        $queries = ($this->queries)();

        $receipt = $queries->allocationReceipt($expectedReceiptRef);
        if ($receipt !== null) {
            $body = $receipt->body;
            $discrepancy = $body['discrepancy'] ?? null;
            $isHeld = is_array($discrepancy) && ($discrepancy['recorded'] ?? false) === true;

            return new ImportObservationOutcome(
                status: $isHeld ? ImportObservationOutcome::STATUS_HELD_DISCREPANCY : ImportObservationOutcome::STATUS_APPLIED,
                receiptKind: $receipt->kind,
                receiptRef: $receipt->receiptRef,
                projectionStatus: $isHeld ? 'held_discrepancy' : 'current',
                receiptBody: $body,
            );
        }

        $caller = ($this->callers)()->current();
        $db = $this->connection();
        $observed = $db->selectOne(
            'SELECT id FROM public.billium_observed_payment '
            .'WHERE installation_ref = ? AND seller_ref = ? AND environment = ? '
            .'AND source_authority = ? AND operation_ref = ?',
            [$caller->installationRef, $caller->sellerRef, $caller->environment, $sourceAuthority, $operationRef],
        );
        if (! is_object($observed)) {
            return new ImportObservationOutcome(status: ImportObservationOutcome::STATUS_ABSENT);
        }

        $observedId = self::intField($observed, 'id');
        $park = $db->selectOne(
            'SELECT dependency_kind, dependency_ref FROM public.billium_observation_park '
            .'WHERE seller_ref = ? AND environment = ? AND observed_payment_id = ? AND revision = ? AND released_at IS NULL',
            [$caller->sellerRef, $caller->environment, $observedId, $operationRevision],
        );
        if (is_object($park)) {
            $depKind = self::strField($park, 'dependency_kind');

            return new ImportObservationOutcome(
                status: ImportObservationOutcome::STATUS_PARKED,
                projectionStatus: $depKind === 'settlement_intent' ? 'allocation_pending' : 'parked_'.$depKind,
            );
        }

        if ($operationKind === 'charge') {
            try {
                $status = $queries->paymentStatus($operationRef, $sourceAuthority);
                $projectionStatus = is_string($status['projectionStatus'] ?? null) ? $status['projectionStatus'] : null;
                if ($projectionStatus === 'held_discrepancy') {
                    return new ImportObservationOutcome(
                        status: ImportObservationOutcome::STATUS_HELD_DISCREPANCY,
                        projectionStatus: $projectionStatus,
                    );
                }

                return new ImportObservationOutcome(
                    status: ImportObservationOutcome::STATUS_PARKED,
                    projectionStatus: $projectionStatus ?? 'allocation_pending',
                );
            } catch (ProblemException) {
                return new ImportObservationOutcome(status: ImportObservationOutcome::STATUS_ABSENT);
            }
        }

        return new ImportObservationOutcome(status: ImportObservationOutcome::STATUS_ABSENT);
    }

    #[Override]
    public function inspectOriginalChargeForRefund(string $sourceAuthority, string $originalPaymentRef, Money $refundMoney): array
    {
        $caller = ($this->callers)()->current();
        $db = $this->connection();
        $payment = $db->selectOne(
            'SELECT p.currency, p.captured_minor, p.refunded_minor, p.financial_hold, o.provider_account_ref '
            .'FROM public.billium_payment p '
            .'LEFT JOIN public.billium_observed_payment o ON o.installation_ref = p.installation_ref '
            .'AND o.seller_ref = p.seller_ref AND o.environment = p.environment '
            .'AND o.source_authority = p.source_authority AND o.source_lineage = p.source_lineage AND o.operation_ref = p.operation_ref '
            .'WHERE p.installation_ref = ? AND p.seller_ref = ? AND p.environment = ? '
            ."AND p.source_authority = ? AND p.payment_ref = ? AND p.kind = 'charge'",
            [$caller->installationRef, $caller->sellerRef, $caller->environment, $sourceAuthority, $originalPaymentRef],
        );
        if (! is_object($payment)) {
            return ['exists' => false];
        }

        $currency = self::strField($payment, 'currency');
        $capturedMinor = self::intField($payment, 'captured_minor');
        $refundedMinor = self::intField($payment, 'refunded_minor');
        $hold = ($payment->financial_hold ?? false) === true;
        $providerAccountRef = is_string($payment->provider_account_ref ?? null) ? $payment->provider_account_ref : '';
        $rawExistingDetails = $db->scalar(
            'SELECT COALESCE(SUM(latest.captured_minor), 0) FROM ('
            .'SELECT DISTINCT ON (o.id) r.captured_minor '
            .'FROM public.billium_observed_payment o '
            .'JOIN public.billium_observed_payment_revision r '
            .'ON r.observed_payment_id = o.id AND r.seller_ref = o.seller_ref AND r.environment = o.environment '
            .'WHERE o.installation_ref = ? AND o.seller_ref = ? AND o.environment = ? '
            ."AND o.source_authority = ? AND o.original_ref = ? AND o.kind = 'refund' "
            .'ORDER BY o.id, r.revision DESC'
            .') latest',
            [$caller->installationRef, $caller->sellerRef, $caller->environment, $sourceAuthority, $originalPaymentRef],
        );
        $existingDetailsMinor = is_int($rawExistingDetails)
            ? $rawExistingDetails
            : (is_string($rawExistingDetails) && preg_match('/^-?[0-9]+$/', $rawExistingDetails) === 1 ? (int) $rawExistingDetails : 0);

        $effectiveCapacity = max(0, $capturedMinor - max($refundedMinor, $existingDetailsMinor));

        return [
            'exists' => true,
            'currency' => $currency,
            'providerAccountRef' => $providerAccountRef,
            'normalCapacityMinor' => $effectiveCapacity,
            'allowed' => ! $hold && $currency === $refundMoney->currency->code && $refundMoney->minor <= $effectiveCapacity,
            'financialHold' => $hold,
        ];
    }

    #[Override]
    public function inspectObligation(string $obligationRef): array
    {
        $caller = ($this->callers)()->current();
        $db = $this->connection();
        $row = $db->selectOne(
            'SELECT o.billing_account_ref, o.customer_ref, o.component_ref, o.basis_ref, o.currency, '
            .'o.amount_due_minor, o.open_minor, o.status, o.terms_digest, o.price_version_ref, o.tax_snapshot_ref, '
            .'i.invoice_ref '
            .'FROM public.billium_obligation o '
            .'LEFT JOIN public.billium_invoice i ON i.id = o.billed_invoice_id '
            .'WHERE o.installation_ref = ? AND o.seller_ref = ? AND o.environment = ? AND o.obligation_ref = ?',
            [$caller->installationRef, $caller->sellerRef, $caller->environment, $obligationRef],
        );
        if (! is_object($row)) {
            return ['exists' => false];
        }

        return [
            'exists' => true,
            'billingAccountRef' => self::strField($row, 'billing_account_ref'),
            'customerRef' => self::strField($row, 'customer_ref'),
            'componentRef' => self::strField($row, 'component_ref'),
            'basisRef' => self::strField($row, 'basis_ref'),
            'currency' => self::strField($row, 'currency'),
            'dueMinor' => self::intField($row, 'amount_due_minor'),
            'openMinor' => self::intField($row, 'open_minor'),
            'status' => self::strField($row, 'status'),
            'invoiceRef' => is_string($row->invoice_ref ?? null) ? $row->invoice_ref : null,
            'termsDigest' => self::strField($row, 'terms_digest'),
            'priceVersionRef' => is_string($row->price_version_ref ?? null) ? $row->price_version_ref : null,
            'taxSnapshotRef' => is_string($row->tax_snapshot_ref ?? null) ? $row->tax_snapshot_ref : null,
        ];
    }

    #[Override]
    public function readScopedConservation(): array
    {
        $caller = ($this->callers)()->current();
        if ($caller->installationRef === '' || $caller->sellerRef === '' || $caller->environment === '') {
            throw new ProblemException(ProblemCode::AuthDenied, 'Conservation read requires an authenticated caller');
        }
        $db = $this->connection();

        /** @var array<string, array<string, int>> $stats */
        $stats = [];
        $ensure = static function (string $currency) use (&$stats): void {
            $stats[$currency] ??= [
                'due' => 0,
                'adjusted' => 0,
                'credited' => 0,
                'settled' => 0,
                'writtenOff' => 0,
                'open' => 0,
                'captured' => 0,
                'refunded' => 0,
                'allocated' => 0,
                'liveAllocations' => 0,
            ];
        };

        $obligations = $db->select(
            'SELECT currency, COALESCE(SUM(amount_due_minor), 0) AS due_minor, COALESCE(SUM(adjusted_minor), 0) AS adjusted_minor, '
            .'COALESCE(SUM(credited_minor), 0) AS credited_minor, COALESCE(SUM(allocated_minor), 0) AS settled_minor, '
            .'COALESCE(SUM(open_minor), 0) AS open_minor '
            .'FROM public.billium_obligation '
            .'WHERE installation_ref = ? AND seller_ref = ? AND environment = ? GROUP BY currency',
            [$caller->installationRef, $caller->sellerRef, $caller->environment],
        );
        foreach ($obligations as $row) {
            $c = self::strField($row, 'currency');
            $ensure($c);
            $stats[$c]['due'] = self::intField($row, 'due_minor');
            $stats[$c]['adjusted'] = self::intField($row, 'adjusted_minor');
            $stats[$c]['credited'] = self::intField($row, 'credited_minor');
            $stats[$c]['settled'] = self::intField($row, 'settled_minor');
            $stats[$c]['open'] = self::intField($row, 'open_minor');
        }

        $payments = $db->select(
            'SELECT currency, COALESCE(SUM(captured_minor), 0) AS captured_minor, '
            .'COALESCE(SUM(refunded_minor), 0) AS refunded_minor, COALESCE(SUM(allocated_minor), 0) AS allocated_minor '
            .'FROM public.billium_payment '
            ."WHERE installation_ref = ? AND seller_ref = ? AND environment = ? AND kind = 'charge' GROUP BY currency",
            [$caller->installationRef, $caller->sellerRef, $caller->environment],
        );
        foreach ($payments as $row) {
            $c = self::strField($row, 'currency');
            $ensure($c);
            $stats[$c]['captured'] = self::intField($row, 'captured_minor');
            $stats[$c]['refunded'] = self::intField($row, 'refunded_minor');
            $stats[$c]['allocated'] = self::intField($row, 'allocated_minor');
        }

        $allocations = $db->select(
            'SELECT currency, COALESCE(SUM(amount_minor), 0) AS live_minor '
            .'FROM public.billium_allocation '
            .'WHERE installation_ref = ? AND seller_ref = ? AND environment = ? GROUP BY currency',
            [$caller->installationRef, $caller->sellerRef, $caller->environment],
        );
        foreach ($allocations as $row) {
            $c = self::strField($row, 'currency');
            $ensure($c);
            $stats[$c]['liveAllocations'] = self::intField($row, 'live_minor');
        }

        ksort($stats, SORT_STRING);
        $result = [];
        foreach ($stats as $currency => $s) {
            $obligationConserved = ($s['due'] + $s['adjusted'] - $s['credited'] - $s['settled'] - $s['writtenOff']) === $s['open'];
            $allocationConserved = $s['settled'] === $s['liveAllocations'] && $s['allocated'] <= $s['liveAllocations'];
            $result[$currency] = new ImportCurrencyConservation(
                currency: $currency,
                obligationsDueMinor: $s['due'],
                obligationsAdjustedMinor: $s['adjusted'],
                obligationsCreditedMinor: $s['credited'],
                obligationsSettledMinor: $s['settled'],
                obligationsWrittenOffMinor: $s['writtenOff'],
                obligationsOpenMinor: $s['open'],
                paymentsCapturedMinor: $s['captured'],
                paymentsRefundedMinor: $s['refunded'],
                paymentsAllocatedMinor: $s['allocated'],
                allocationsLiveMinor: $s['liveAllocations'],
                conserved: $obligationConserved && $allocationConserved,
            );
        }

        return $result;
    }

    private function connection(): Connection
    {
        return $this->databases->connection($this->options->connection);
    }

    private static function strField(mixed $row, string $column): string
    {
        $val = is_object($row) ? (get_object_vars($row)[$column] ?? null) : null;
        if (! is_string($val)) {
            throw new LogicException("Expected string column {$column}");
        }

        return $val;
    }

    private static function intField(mixed $row, string $column): int
    {
        $val = is_object($row) ? (get_object_vars($row)[$column] ?? null) : null;
        if (is_int($val)) {
            return $val;
        }
        if (is_string($val) && preg_match('/^-?[0-9]+$/', $val) === 1) {
            return (int) $val;
        }

        throw new LogicException("Expected integer column {$column}");
    }
}
