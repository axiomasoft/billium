<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Accounting;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Platform\Contracts\Accounting\AccountingMapper;
use Billium\Platform\Data\Accounting\AccountingFactCategory;
use Billium\Platform\Data\Accounting\AccountingFactEnvelope;
use Billium\Platform\Data\Accounting\AccountingFactKind;
use Billium\Platform\Data\Accounting\AccountingMappingResult;
use Billium\Platform\Data\Accounting\AccountingSourceRecord;
use Billium\Platform\Data\Accounting\FinBridgeExportEnvelope;
use Billium\Platform\Data\Accounting\QuarantinedFactRecord;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Override;
use Packman\Attributes\Internal;

/**
 * Deterministic platform-owned accounting fact mapper and FinBridge contract translator (P6.6, D53, D56, D63).
 *
 * Strictly separates:
 * - CashIn / CashOut from unique authenticated actual provider effects (never bounded refundedTotal or allocations);
 * - NoncashExposure keyed by complete discrepancy basis fingerprint (linked, never double-counted as cash);
 * - Receivable facts from bounded ledger projections (linked corrections via originalFactRef);
 * - NoncashAllocation facts kept separate from cash facts;
 * - Unknown/unsupported schemas preserved in quarantine rather than dropped.
 */
#[Internal]
final readonly class AccountingFactMapper implements AccountingMapper
{
    /** @var list<string> */
    public const array DEFAULT_INPUT_SCHEMAS = [
        'billium.event.v1',
        'billium.event.v2',
        'accounting.source.v1',
    ];

    /** @var list<string> */
    private const array SUPPORTED_EXPORT_CONTRACTS = [
        FinBridgeExportEnvelope::SCHEMA_V1,
    ];

    /** @var list<string> */
    private const array FORBIDDEN_GL_KEYS = [
        'glAccount',
        'gl_account',
        'debitAccount',
        'debit_account',
        'creditAccount',
        'credit_account',
        'journalEntry',
        'journal_entry',
        'journalLines',
        'journal_lines',
        'chartOfAccounts',
        'chart_of_accounts',
        'ledgerAccount',
        'ledger_account',
    ];

    private Codec $codec;

    /** @var list<string> */
    private array $supportedInputSchemas;

    /**
     * @param  list<string>|null  $supportedInputSchemas
     */
    public function __construct(?Codec $codec = null, ?array $supportedInputSchemas = null)
    {
        $this->codec = $codec ?? new Codec;
        $schemas = $supportedInputSchemas ?? self::DEFAULT_INPUT_SCHEMAS;
        $normalized = [];
        foreach ($schemas as $schema) {
            if (trim($schema) === '' || strlen($schema) > 200 || str_contains($schema, "\0")) {
                throw new InvalidArgumentException('Supported accounting input schema must be a bounded nonempty string');
            }
            if (! in_array($schema, $normalized, true)) {
                $normalized[] = $schema;
            }
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('AccountingFactMapper requires at least one supported input schema');
        }
        $this->supportedInputSchemas = $normalized;
    }

    #[Override]
    public function supportedInputSchemas(): array
    {
        return $this->supportedInputSchemas;
    }

    #[Override]
    public function supportsInputSchema(string $schemaVersion): bool
    {
        return in_array($schemaVersion, $this->supportedInputSchemas, true);
    }

    #[Override]
    public function withAdditionalInputSchemas(array $schemas): self
    {
        return new self($this->codec, [...$this->supportedInputSchemas, ...$schemas]);
    }

    #[Override]
    public function map(AccountingSourceRecord $record): AccountingMappingResult
    {
        if (! $this->supportsInputSchema($record->schemaVersion)) {
            $quarantineDigest = $this->codec->digest([
                'schemaVersion' => $record->schemaVersion,
                'eventType' => $record->eventType,
                'installationRef' => $record->installationRef,
                'sellerRef' => $record->sellerRef,
                'environment' => $record->environment,
                'aggregateRef' => $record->aggregateRef,
                'aggregateRevision' => $record->aggregateRevision,
                'payload' => $record->payload,
            ]);

            return AccountingMappingResult::quarantined(new QuarantinedFactRecord(
                quarantineRef: 'quarantine:'.substr($quarantineDigest, 7, 32),
                schemaVersion: $record->schemaVersion,
                eventType: $record->eventType,
                reason: "unsupported_input_schema:{$record->schemaVersion}",
                sourceRecord: $record,
                quarantinedAt: $record->occurredAt,
            ));
        }

        return match ($record->eventType) {
            'payment.effect.outcome.v1',
            'payment.captured',
            'payment.captured.v1',
            'refund.succeeded',
            'refund.succeeded.v1' => $this->mapActualCash($record),

            'exposure.over_refund',
            'exposure.over_refund.v1',
            'exposure.over_capture',
            'exposure.over_capture.v1',
            'exposure.discrepancy',
            'exposure.discrepancy.v1' => $this->mapNoncashExposure($record),

            'invoice.issued',
            'invoice.issued.v1' => $this->mapInvoiceIssued($record),

            'credit_note.issued',
            'credit_note.issued.v1' => $this->mapCreditNoteIssued($record),

            'write_off.recorded',
            'write_off.recorded.v1',
            'invoice.uncollectible.v1' => $this->mapWriteOffRecorded($record),

            'allocation.applied',
            'allocation.applied.v1',
            'allocation.reversed',
            'allocation.reversed.v1' => $this->mapAllocation($record),

            default => AccountingMappingResult::ignored("non_accounting_event_type:{$record->eventType}"),
        };
    }

    #[Override]
    public function toFinBridge(
        AccountingFactEnvelope $fact,
        string $contractVersion = FinBridgeExportEnvelope::SCHEMA_V1,
    ): FinBridgeExportEnvelope {
        if (! in_array($contractVersion, self::SUPPORTED_EXPORT_CONTRACTS, true)) {
            throw new ProblemException(
                ProblemCode::SchemaUnsupported,
                "Unsupported FinBridge export contract version '{$contractVersion}'",
            );
        }

        $attributes = self::stripForbiddenGlFields($fact->metadata);
        $attributes['economicEffectRef'] = $fact->economicEffectRef;

        $canonical = [
            'schemaVersion' => $contractVersion,
            'sourceFactRef' => $fact->sourceFactRef,
            'factKind' => $fact->factKind->value,
            'category' => $fact->category->value,
            'installationRef' => $fact->installationRef,
            'sellerRef' => $fact->sellerRef,
            'environment' => $fact->environment,
            'sourceLineage' => $fact->sourceLineage,
            'currency' => $fact->money->currency->code,
            'amountMinor' => $fact->money->minor,
            'amountDecimal' => $fact->money->amount(),
            'occurredAt' => self::utc($fact->occurredAt),
            'snapshotDigest' => $fact->snapshotDigest,
            'originalFactRef' => $fact->originalFactRef,
            'linkedCashFactRefs' => $fact->linkedCashFactRefs,
            'exceptional' => $fact->exceptional,
            'attributes' => $attributes,
        ];

        return new FinBridgeExportEnvelope(
            schemaVersion: $contractVersion,
            sourceFactRef: $fact->sourceFactRef,
            factKind: $fact->factKind,
            category: $fact->category,
            installationRef: $fact->installationRef,
            sellerRef: $fact->sellerRef,
            environment: $fact->environment,
            sourceLineage: $fact->sourceLineage,
            currency: $fact->money->currency->code,
            amountMinor: $fact->money->minor,
            amountDecimal: $fact->money->amount(),
            occurredAt: $fact->occurredAt,
            snapshotDigest: $fact->snapshotDigest,
            exportDigest: $this->codec->digest($canonical),
            originalFactRef: $fact->originalFactRef,
            linkedCashFactRefs: $fact->linkedCashFactRefs,
            exceptional: $fact->exceptional,
            attributes: $attributes,
        );
    }

    private function mapActualCash(AccountingSourceRecord $record): AccountingMappingResult
    {
        $payload = $record->payload;

        if (($payload['ambiguous'] ?? false) === true) {
            return AccountingMappingResult::ignored('ambiguous_aggregate_evidence');
        }
        $evidenceKind = self::nonEmptyString($payload['evidenceKind'] ?? null);
        if ($evidenceKind !== null && in_array($evidenceKind, ['aggregate_only', 'bounded_projection', 'allocation'], true)) {
            return AccountingMappingResult::ignored("non_actual_evidence_kind:{$evidenceKind}");
        }
        $derivedFrom = self::nonEmptyString($payload['derivedFrom'] ?? null);
        if ($derivedFrom !== null && in_array($derivedFrom, ['bounded_refunded_total', 'allocation', 'applied_projection'], true)) {
            return AccountingMappingResult::ignored("derived_from_non_actual_source:{$derivedFrom}");
        }

        $factKind = $this->resolveCashFactKind($record->eventType, $payload);
        if ($factKind === null) {
            return AccountingMappingResult::ignored('non_cash_operation_kind');
        }

        $status = self::nonEmptyString($payload['status'] ?? null);
        if ($record->eventType === 'payment.effect.outcome.v1') {
            if ($status === null || ! in_array($status, ['succeeded', 'confirmed'], true)) {
                return AccountingMappingResult::ignored('unconfirmed_effect_status:'.($status ?? 'missing'));
            }
        } elseif ($status !== null && ! in_array($status, ['succeeded', 'confirmed'], true)) {
            return AccountingMappingResult::ignored("unconfirmed_effect_status:{$status}");
        }

        $providerAccountRef = self::nonEmptyString($payload['providerAccountRef'] ?? null)
            ?? self::nonEmptyString($payload['merchantAccountRef'] ?? null);

        $providerEffectRef = $this->resolveProviderEffectRef($record->eventType, $payload);
        if ($providerAccountRef === null || $providerEffectRef === null) {
            return AccountingMappingResult::ignored('missing_authoritative_provider_effect_identity');
        }

        $money = $this->extractActualCashMoney($record->eventType, $payload);
        if ($money === null || $money->minor <= 0) {
            return AccountingMappingResult::ignored('zero_or_missing_actual_cash_amount');
        }

        $sourceFactRef = $this->cashFactRef($record->sellerRef, $record->environment, $factKind, $providerAccountRef, $providerEffectRef);
        $economicEffectRef = "{$providerAccountRef}|{$providerEffectRef}";
        $originalFactRef = self::nonEmptyString($payload['originalFactRef'] ?? null);
        $exceptional = ($payload['exceptional'] ?? false) === true;

        $metadata = self::filterNonNull([
            'providerAccountRef' => $providerAccountRef,
            'providerEffectRef' => $providerEffectRef,
            'operationRef' => self::nonEmptyString($payload['operationRef'] ?? null),
            'originalOperationRef' => self::nonEmptyString($payload['originalOperationRef'] ?? null),
            'providerEvidenceRef' => self::nonEmptyString($payload['providerEvidenceRef'] ?? null),
            'bindingRevision' => self::nonEmptyString($payload['bindingRevision'] ?? null),
            'reasonCode' => self::nonEmptyString($payload['reasonCode'] ?? null),
        ]);

        return AccountingMappingResult::mapped(new AccountingFactEnvelope(
            schemaVersion: AccountingFactEnvelope::SCHEMA_V1,
            sourceFactRef: $sourceFactRef,
            factKind: $factKind,
            category: $factKind->category(),
            installationRef: $record->installationRef,
            sellerRef: $record->sellerRef,
            environment: $record->environment,
            sourceLineage: $record->sourceLineage,
            economicEffectRef: $economicEffectRef,
            money: $money,
            occurredAt: $record->occurredAt,
            snapshotDigest: $this->snapshotDigest($record, $sourceFactRef, $money, $metadata),
            originalFactRef: $originalFactRef,
            linkedCashFactRefs: [],
            exceptional: $exceptional,
            metadata: $metadata,
        ));
    }

    private function mapNoncashExposure(AccountingSourceRecord $record): AccountingMappingResult
    {
        $payload = $record->payload;
        $reason = self::nonEmptyString($payload['reason'] ?? null);

        $factKind = match ($record->eventType) {
            'exposure.over_refund', 'exposure.over_refund.v1' => AccountingFactKind::OverRefundExposure,
            'exposure.over_capture', 'exposure.over_capture.v1' => AccountingFactKind::OverCaptureExposure,
            default => match ($reason) {
                'over_refund' => AccountingFactKind::OverRefundExposure,
                'over_capture' => AccountingFactKind::OverCaptureExposure,
                default => AccountingFactKind::DiscrepancyExposure,
            },
        };

        $money = $this->extractMoney($payload, ['exposureMoney', 'money'], ['exposure_minor', 'exposureMinor', 'amount_minor', 'amountMinor']);
        if ($money === null || $money->minor <= 0) {
            return AccountingMappingResult::ignored('non_positive_exposure_amount');
        }

        $contributing = $this->normalizeContributingEffects($payload['contributingEffects'] ?? null);
        if ($contributing === []) {
            return AccountingMappingResult::ignored('missing_discrepancy_contributing_effects');
        }

        $rootOperationRef = self::nonEmptyString($payload['rootOperationRef'] ?? null)
            ?? self::nonEmptyString($payload['paymentRef'] ?? null)
            ?? $record->aggregateRef;
        $effectiveReason = $reason ?? $factKind->value;

        $basisFingerprint = $this->codec->digest([
            'sellerRef' => $record->sellerRef,
            'environment' => $record->environment,
            'factKind' => $factKind->value,
            'rootOperationRef' => $rootOperationRef,
            'reason' => $effectiveReason,
            'currency' => $money->currency->code,
            'exposureMinor' => $money->minor,
            'contributingEffects' => $contributing,
        ]);

        $sourceFactRef = 'fact:'.$factKind->value.':'.substr($basisFingerprint, 7, 32);
        $linkedCash = self::normalizeStringList($payload['linkedCashFactRefs'] ?? []);
        $originalFactRef = self::nonEmptyString($payload['originalFactRef'] ?? null);

        $metadata = self::filterNonNull([
            'rootOperationRef' => $rootOperationRef,
            'reason' => $effectiveReason,
            'discrepancyBasisDigest' => $basisFingerprint,
            'contributingEffects' => $contributing,
            'discrepancyRef' => self::nonEmptyString($payload['discrepancyRef'] ?? null),
            'observedRefundedMinor' => self::optionalInt($payload['observedRefundedMinor'] ?? null),
            'appliedRefundedMinor' => self::optionalInt($payload['appliedRefundedMinor'] ?? null),
            'appliedCapturedMinor' => self::optionalInt($payload['appliedCapturedMinor'] ?? null),
        ]);

        return AccountingMappingResult::mapped(new AccountingFactEnvelope(
            schemaVersion: AccountingFactEnvelope::SCHEMA_V1,
            sourceFactRef: $sourceFactRef,
            factKind: $factKind,
            category: AccountingFactCategory::NoncashExposure,
            installationRef: $record->installationRef,
            sellerRef: $record->sellerRef,
            environment: $record->environment,
            sourceLineage: $record->sourceLineage,
            economicEffectRef: "exposure:{$rootOperationRef}:".substr($basisFingerprint, 7, 16),
            money: $money,
            occurredAt: $record->occurredAt,
            snapshotDigest: $this->snapshotDigest($record, $sourceFactRef, $money, $metadata),
            originalFactRef: $originalFactRef,
            linkedCashFactRefs: $linkedCash,
            exceptional: true,
            metadata: $metadata,
        ));
    }

    private function mapInvoiceIssued(AccountingSourceRecord $record): AccountingMappingResult
    {
        $payload = $record->payload;
        if (($payload['bounded'] ?? true) === false || ($payload['projectionState'] ?? null) === 'held_inconsistent') {
            return AccountingMappingResult::ignored('unbounded_receivable_projection');
        }

        $invoiceRef = self::nonEmptyString($payload['invoice_ref'] ?? null)
            ?? self::nonEmptyString($payload['invoiceRef'] ?? null)
            ?? $record->aggregateRef;

        $money = $this->extractMoney($payload, ['money'], ['total_minor', 'totalMinor', 'amount_minor', 'amountMinor']);
        if ($money === null || $money->minor <= 0) {
            return AccountingMappingResult::ignored('zero_or_missing_invoice_amount');
        }

        $sourceFactRef = $this->namedFactRef($record->sellerRef, $record->environment, AccountingFactKind::InvoiceIssued, $invoiceRef);
        $originalFactRef = self::nonEmptyString($payload['originalFactRef'] ?? null);
        if ($originalFactRef === null) {
            $replacesInvoiceRef = self::nonEmptyString($payload['replaces_invoice_ref'] ?? null)
                ?? self::nonEmptyString($payload['replacesInvoiceRef'] ?? null);
            if ($replacesInvoiceRef !== null) {
                $originalFactRef = $this->namedFactRef($record->sellerRef, $record->environment, AccountingFactKind::InvoiceIssued, $replacesInvoiceRef);
            }
        }

        $metadata = self::filterNonNull([
            'invoiceRef' => $invoiceRef,
            'billingAccountRef' => self::nonEmptyString($payload['billing_account_ref'] ?? null)
                ?? self::nonEmptyString($payload['billingAccountRef'] ?? null),
            'legalDocumentRef' => self::nonEmptyString($payload['legal_document_ref'] ?? null)
                ?? self::nonEmptyString($payload['legalDocumentRef'] ?? null),
        ]);

        return AccountingMappingResult::mapped(new AccountingFactEnvelope(
            schemaVersion: AccountingFactEnvelope::SCHEMA_V1,
            sourceFactRef: $sourceFactRef,
            factKind: AccountingFactKind::InvoiceIssued,
            category: AccountingFactCategory::Receivable,
            installationRef: $record->installationRef,
            sellerRef: $record->sellerRef,
            environment: $record->environment,
            sourceLineage: $record->sourceLineage,
            economicEffectRef: $invoiceRef,
            money: $money,
            occurredAt: $record->occurredAt,
            snapshotDigest: $this->snapshotDigest($record, $sourceFactRef, $money, $metadata),
            originalFactRef: $originalFactRef,
            linkedCashFactRefs: [],
            exceptional: false,
            metadata: $metadata,
        ));
    }

    private function mapCreditNoteIssued(AccountingSourceRecord $record): AccountingMappingResult
    {
        $payload = $record->payload;
        if (($payload['bounded'] ?? true) === false || ($payload['projectionState'] ?? null) === 'held_inconsistent') {
            return AccountingMappingResult::ignored('unbounded_receivable_projection');
        }

        $creditNoteRef = self::nonEmptyString($payload['credit_note_ref'] ?? null)
            ?? self::nonEmptyString($payload['creditNoteRef'] ?? null);
        if ($creditNoteRef === null) {
            return AccountingMappingResult::ignored('missing_credit_note_ref');
        }

        $money = $this->extractMoney($payload, ['money'], ['total_minor', 'totalMinor', 'amount_minor', 'amountMinor'])
            ?? $this->sumLineMinorAmounts($payload['lines'] ?? null, self::nonEmptyString($payload['currency'] ?? null));
        if ($money === null || $money->minor <= 0) {
            return AccountingMappingResult::ignored('zero_or_missing_credit_note_amount');
        }

        $invoiceRef = self::nonEmptyString($payload['invoice_ref'] ?? null)
            ?? self::nonEmptyString($payload['invoiceRef'] ?? null);

        $sourceFactRef = $this->namedFactRef($record->sellerRef, $record->environment, AccountingFactKind::CreditNoteIssued, $creditNoteRef);
        $originalFactRef = self::nonEmptyString($payload['originalFactRef'] ?? null);
        if ($originalFactRef === null && $invoiceRef !== null) {
            $originalFactRef = $this->namedFactRef($record->sellerRef, $record->environment, AccountingFactKind::InvoiceIssued, $invoiceRef);
        }

        $metadata = self::filterNonNull([
            'creditNoteRef' => $creditNoteRef,
            'invoiceRef' => $invoiceRef,
            'billingAccountRef' => self::nonEmptyString($payload['billing_account_ref'] ?? null)
                ?? self::nonEmptyString($payload['billingAccountRef'] ?? null),
            'mode' => self::nonEmptyString($payload['mode'] ?? null),
            'legalDocumentRef' => self::nonEmptyString($payload['legal_document_ref'] ?? null)
                ?? self::nonEmptyString($payload['legalDocumentRef'] ?? null),
        ]);

        return AccountingMappingResult::mapped(new AccountingFactEnvelope(
            schemaVersion: AccountingFactEnvelope::SCHEMA_V1,
            sourceFactRef: $sourceFactRef,
            factKind: AccountingFactKind::CreditNoteIssued,
            category: AccountingFactCategory::Receivable,
            installationRef: $record->installationRef,
            sellerRef: $record->sellerRef,
            environment: $record->environment,
            sourceLineage: $record->sourceLineage,
            economicEffectRef: $creditNoteRef,
            money: $money,
            occurredAt: $record->occurredAt,
            snapshotDigest: $this->snapshotDigest($record, $sourceFactRef, $money, $metadata),
            originalFactRef: $originalFactRef,
            linkedCashFactRefs: [],
            exceptional: false,
            metadata: $metadata,
        ));
    }

    private function mapWriteOffRecorded(AccountingSourceRecord $record): AccountingMappingResult
    {
        $payload = $record->payload;
        if (($payload['bounded'] ?? true) === false || ($payload['projectionState'] ?? null) === 'held_inconsistent') {
            return AccountingMappingResult::ignored('unbounded_receivable_projection');
        }

        $writeOffRef = self::nonEmptyString($payload['write_off_ref'] ?? null)
            ?? self::nonEmptyString($payload['writeOffRef'] ?? null)
            ?? self::nonEmptyString($payload['receipt_ref'] ?? null)
            ?? $record->aggregateRef;

        $money = $this->extractMoney($payload, ['money'], ['write_off_minor', 'writeOffMinor', 'total_minor', 'totalMinor', 'amount_minor', 'amountMinor']);
        if ($money === null || $money->minor <= 0) {
            return AccountingMappingResult::ignored('zero_or_missing_write_off_amount');
        }

        $invoiceRef = self::nonEmptyString($payload['invoice_ref'] ?? null)
            ?? self::nonEmptyString($payload['invoiceRef'] ?? null);

        $sourceFactRef = $this->namedFactRef($record->sellerRef, $record->environment, AccountingFactKind::WriteOffRecorded, $writeOffRef);
        $originalFactRef = self::nonEmptyString($payload['originalFactRef'] ?? null);
        if ($originalFactRef === null && $invoiceRef !== null) {
            $originalFactRef = $this->namedFactRef($record->sellerRef, $record->environment, AccountingFactKind::InvoiceIssued, $invoiceRef);
        }

        $metadata = self::filterNonNull([
            'writeOffRef' => $writeOffRef,
            'invoiceRef' => $invoiceRef,
            'billingAccountRef' => self::nonEmptyString($payload['billing_account_ref'] ?? null)
                ?? self::nonEmptyString($payload['billingAccountRef'] ?? null),
            'reason' => self::nonEmptyString($payload['reason'] ?? null),
        ]);

        return AccountingMappingResult::mapped(new AccountingFactEnvelope(
            schemaVersion: AccountingFactEnvelope::SCHEMA_V1,
            sourceFactRef: $sourceFactRef,
            factKind: AccountingFactKind::WriteOffRecorded,
            category: AccountingFactCategory::Receivable,
            installationRef: $record->installationRef,
            sellerRef: $record->sellerRef,
            environment: $record->environment,
            sourceLineage: $record->sourceLineage,
            economicEffectRef: $writeOffRef,
            money: $money,
            occurredAt: $record->occurredAt,
            snapshotDigest: $this->snapshotDigest($record, $sourceFactRef, $money, $metadata),
            originalFactRef: $originalFactRef,
            linkedCashFactRefs: [],
            exceptional: false,
            metadata: $metadata,
        ));
    }

    private function mapAllocation(AccountingSourceRecord $record): AccountingMappingResult
    {
        $payload = $record->payload;
        $reversesRef = self::nonEmptyString($payload['reverses_receipt_ref'] ?? null)
            ?? self::nonEmptyString($payload['reversesAllocationRef'] ?? null);
        $isReversal = in_array($record->eventType, ['allocation.reversed', 'allocation.reversed.v1'], true)
            || $reversesRef !== null;

        if ($isReversal) {
            $reversalRef = self::nonEmptyString($payload['reversal_ref'] ?? null)
                ?? self::nonEmptyString($payload['reversalRef'] ?? null)
                ?? self::nonEmptyString($payload['receipt_ref'] ?? null);
            if ($reversalRef === null) {
                return AccountingMappingResult::ignored('missing_allocation_reversal_ref');
            }

            $money = $this->extractMoney(
                $payload,
                ['money'],
                ['reversed_minor', 'reversedMinor', 'amount_minor', 'amountMinor'],
                allowNegativeMagnitude: true,
            );
            if ($money === null || $money->minor <= 0) {
                return AccountingMappingResult::ignored('zero_or_missing_allocation_reversal_amount');
            }

            $sourceFactRef = $this->namedFactRef($record->sellerRef, $record->environment, AccountingFactKind::AllocationReversed, $reversalRef);
            $originalFactRef = self::nonEmptyString($payload['originalFactRef'] ?? null);
            if ($originalFactRef === null && $reversesRef !== null) {
                $originalFactRef = $this->namedFactRef($record->sellerRef, $record->environment, AccountingFactKind::AllocationApplied, $reversesRef);
            }

            $metadata = self::filterNonNull([
                'reversalRef' => $reversalRef,
                'reversesAllocationRef' => $reversesRef,
                'obligationRef' => self::nonEmptyString($payload['obligation_ref'] ?? null)
                    ?? self::nonEmptyString($payload['obligationRef'] ?? null),
                'invoiceRef' => self::nonEmptyString($payload['invoice_ref'] ?? null)
                    ?? self::nonEmptyString($payload['invoiceRef'] ?? null),
                'cause' => self::nonEmptyString($payload['cause'] ?? null),
            ]);

            return AccountingMappingResult::mapped(new AccountingFactEnvelope(
                schemaVersion: AccountingFactEnvelope::SCHEMA_V1,
                sourceFactRef: $sourceFactRef,
                factKind: AccountingFactKind::AllocationReversed,
                category: AccountingFactCategory::NoncashAllocation,
                installationRef: $record->installationRef,
                sellerRef: $record->sellerRef,
                environment: $record->environment,
                sourceLineage: $record->sourceLineage,
                economicEffectRef: $reversalRef,
                money: $money,
                occurredAt: $record->occurredAt,
                snapshotDigest: $this->snapshotDigest($record, $sourceFactRef, $money, $metadata),
                originalFactRef: $originalFactRef,
                linkedCashFactRefs: self::normalizeStringList($payload['linkedCashFactRefs'] ?? []),
                exceptional: false,
                metadata: $metadata,
            ));
        }

        $allocationRef = self::nonEmptyString($payload['receipt_ref'] ?? null)
            ?? self::nonEmptyString($payload['allocation_ref'] ?? null)
            ?? self::nonEmptyString($payload['allocationRef'] ?? null);
        if ($allocationRef === null) {
            return AccountingMappingResult::ignored('missing_allocation_ref');
        }

        $money = $this->extractMoney($payload, ['money'], ['amount_minor', 'amountMinor']);
        if ($money === null || $money->minor <= 0) {
            return AccountingMappingResult::ignored('zero_or_missing_allocation_amount');
        }

        $sourceFactRef = $this->namedFactRef($record->sellerRef, $record->environment, AccountingFactKind::AllocationApplied, $allocationRef);
        $linkedCash = self::normalizeStringList($payload['linkedCashFactRefs'] ?? []);
        $singleLinked = self::nonEmptyString($payload['sourceCashFactRef'] ?? null);
        if ($singleLinked !== null && ! in_array($singleLinked, $linkedCash, true)) {
            $linkedCash[] = $singleLinked;
            sort($linkedCash, SORT_STRING);
        }

        $metadata = self::filterNonNull([
            'allocationRef' => $allocationRef,
            'obligationRef' => self::nonEmptyString($payload['obligation_ref'] ?? null)
                ?? self::nonEmptyString($payload['obligationRef'] ?? null),
            'invoiceRef' => self::nonEmptyString($payload['invoice_ref'] ?? null)
                ?? self::nonEmptyString($payload['invoiceRef'] ?? null),
            'sourceKind' => self::nonEmptyString($payload['source_kind'] ?? null)
                ?? self::nonEmptyString($payload['sourceKind'] ?? null),
            'sourceRef' => self::nonEmptyString($payload['source_ref'] ?? null)
                ?? self::nonEmptyString($payload['sourceRef'] ?? null),
        ]);

        return AccountingMappingResult::mapped(new AccountingFactEnvelope(
            schemaVersion: AccountingFactEnvelope::SCHEMA_V1,
            sourceFactRef: $sourceFactRef,
            factKind: AccountingFactKind::AllocationApplied,
            category: AccountingFactCategory::NoncashAllocation,
            installationRef: $record->installationRef,
            sellerRef: $record->sellerRef,
            environment: $record->environment,
            sourceLineage: $record->sourceLineage,
            economicEffectRef: $allocationRef,
            money: $money,
            occurredAt: $record->occurredAt,
            snapshotDigest: $this->snapshotDigest($record, $sourceFactRef, $money, $metadata),
            linkedCashFactRefs: $linkedCash,
            exceptional: false,
            metadata: $metadata,
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveCashFactKind(string $eventType, array $payload): ?AccountingFactKind
    {
        if ($eventType === 'payment.effect.outcome.v1') {
            $operationKind = self::nonEmptyString($payload['operationKind'] ?? null);

            return match ($operationKind) {
                'capture' => AccountingFactKind::PaymentCaptured,
                'refund' => AccountingFactKind::RefundSucceeded,
                default => null,
            };
        }

        return match ($eventType) {
            'payment.captured', 'payment.captured.v1' => AccountingFactKind::PaymentCaptured,
            'refund.succeeded', 'refund.succeeded.v1' => AccountingFactKind::RefundSucceeded,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveProviderEffectRef(string $eventType, array $payload): ?string
    {
        if (array_key_exists('providerEffectRef', $payload)) {
            return self::nonEmptyString($payload['providerEffectRef']);
        }
        $stable = self::nonEmptyString($payload['stableEffectId'] ?? null);
        if ($stable !== null) {
            return $stable;
        }
        if ($eventType === 'payment.effect.outcome.v1') {
            return self::nonEmptyString($payload['providerEvidenceRef'] ?? null);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractActualCashMoney(string $eventType, array $payload): ?Money
    {
        // Never derive cash from bounded/cumulative refundedTotal or capturedTotal.
        foreach (['effectMoney', 'actualMoney'] as $wireKey) {
            $money = self::parseMoneyWire($payload[$wireKey] ?? null);
            if ($money !== null) {
                return $money;
            }
        }

        if ($eventType === 'payment.effect.outcome.v1') {
            $effectTotal = self::nonEmptyString($payload['effectTotal'] ?? null);
            $currency = is_array($payload['money'] ?? null)
                ? self::nonEmptyString($payload['money']['currency'] ?? null)
                : self::nonEmptyString($payload['currency'] ?? null);
            if ($effectTotal === null || $currency === null) {
                return null;
            }

            return Money::parse($effectTotal, $currency);
        }

        return $this->extractMoney($payload, ['money'], ['effect_minor', 'effectMinor', 'amount_minor', 'amountMinor']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $wireKeys
     * @param  list<string>  $minorKeys
     */
    private function extractMoney(
        array $payload,
        array $wireKeys,
        array $minorKeys,
        bool $allowNegativeMagnitude = false,
    ): ?Money {
        foreach ($wireKeys as $key) {
            $money = self::parseMoneyWire($payload[$key] ?? null);
            if ($money !== null) {
                if ($allowNegativeMagnitude && $money->minor < 0) {
                    return new Money(abs($money->minor), $money->currency);
                }

                return $money;
            }
        }

        $currencyCode = self::nonEmptyString($payload['currency'] ?? null);
        if ($currencyCode === null) {
            return null;
        }

        foreach ($minorKeys as $key) {
            $minor = self::parseIntValue($payload[$key] ?? null);
            if ($minor !== null) {
                $value = $allowNegativeMagnitude ? abs($minor) : $minor;

                return new Money($value, Currency::of($currencyCode));
            }
        }

        return null;
    }

    private function sumLineMinorAmounts(mixed $lines, ?string $currencyCode): ?Money
    {
        if (! is_array($lines) || $lines === [] || $currencyCode === null) {
            return null;
        }
        $sum = 0;
        $counted = 0;
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $minor = self::parseIntValue($line['amount_minor'] ?? $line['amountMinor'] ?? null);
            if ($minor === null || $minor <= 0) {
                continue;
            }
            $sum += $minor;
            $counted++;
        }

        return $counted > 0 ? new Money($sum, Currency::of($currencyCode)) : null;
    }

    /**
     * @return list<array{effectRef: string, revision: string, digest: string}>
     */
    private function normalizeContributingEffects(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw) || $raw === []) {
            return [];
        }
        $normalized = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                return [];
            }
            $effectRef = self::nonEmptyString($entry['providerEffectRef'] ?? null)
                ?? self::nonEmptyString($entry['effectRef'] ?? null);
            $digest = self::nonEmptyString($entry['digest'] ?? null)
                ?? self::nonEmptyString($entry['evidenceDigest'] ?? null);
            $revRaw = $entry['revision'] ?? null;
            $revision = is_int($revRaw) ? (string) $revRaw : self::nonEmptyString($revRaw);
            if ($effectRef === null || $digest === null || $revision === null) {
                return [];
            }
            $normalized[] = [
                'effectRef' => $effectRef,
                'revision' => $revision,
                'digest' => $digest,
            ];
        }
        usort($normalized, static fn (array $a, array $b): int => [$a['effectRef'], $a['revision'], $a['digest']] <=> [$b['effectRef'], $b['revision'], $b['digest']]);

        return $normalized;
    }

    private function cashFactRef(
        string $sellerRef,
        string $environment,
        AccountingFactKind $factKind,
        string $providerAccountRef,
        string $providerEffectRef,
    ): string {
        $digest = $this->codec->digest([
            'sellerRef' => $sellerRef,
            'environment' => $environment,
            'factKind' => $factKind->value,
            'providerAccountRef' => $providerAccountRef,
            'providerEffectRef' => $providerEffectRef,
        ]);

        return 'fact:'.$factKind->value.':'.substr($digest, 7, 32);
    }

    private function namedFactRef(
        string $sellerRef,
        string $environment,
        AccountingFactKind $factKind,
        string $economicEffectRef,
    ): string {
        $digest = $this->codec->digest([
            'sellerRef' => $sellerRef,
            'environment' => $environment,
            'factKind' => $factKind->value,
            'economicEffectRef' => $economicEffectRef,
        ]);

        return 'fact:'.$factKind->value.':'.substr($digest, 7, 32);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function snapshotDigest(
        AccountingSourceRecord $record,
        string $sourceFactRef,
        Money $money,
        array $metadata,
    ): string {
        if ($record->snapshotDigest !== null) {
            return $record->snapshotDigest;
        }

        return $this->codec->digest([
            'sourceFactRef' => $sourceFactRef,
            'installationRef' => $record->installationRef,
            'sellerRef' => $record->sellerRef,
            'environment' => $record->environment,
            'sourceLineage' => $record->sourceLineage,
            'money' => $money->toWire(),
            'occurredAt' => self::utc($record->occurredAt),
            'metadata' => $metadata,
        ]);
    }

    private static function parseMoneyWire(mixed $value): ?Money
    {
        if (! is_array($value) || ! isset($value['amount'], $value['currency'])) {
            return null;
        }
        if (! is_string($value['amount']) || ! is_string($value['currency'])) {
            return null;
        }

        return Money::parse($value['amount'], $value['currency']);
    }

    private static function parseIntValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?(0|[1-9][0-9]*)$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private static function optionalInt(mixed $value): ?int
    {
        return self::parseIntValue($value);
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<string>
     */
    private static function normalizeStringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $items = [];
        foreach ($raw as $value) {
            $text = self::nonEmptyString($value);
            if ($text !== null && ! in_array($text, $items, true)) {
                $items[] = $text;
            }
        }
        sort($items, SORT_STRING);

        return $items;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function filterNonNull(array $values): array
    {
        $filtered = [];
        foreach ($values as $key => $value) {
            if ($value !== null) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * Ensures no GL account or journal entry fields leak into FinBridge fact export payloads.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private static function stripForbiddenGlFields(array $metadata): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            if (in_array($key, self::FORBIDDEN_GL_KEYS, true)) {
                continue;
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    private static function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
