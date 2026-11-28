<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Accounting;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Platform\Contracts\Accounting\AccountingMapper;
use Billium\Platform\Data\Accounting\AccountingFactEnvelope;
use Billium\Platform\Data\Accounting\AccountingMappingResult;
use Billium\Platform\Data\Accounting\AccountingSourceRecord;
use Billium\Platform\Data\Accounting\FinBridgeExportEnvelope;
use Billium\Platform\Data\Accounting\QuarantinedFactRecord;
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

    private ActualCashFactMapper $cashMapper;

    private NoncashExposureFactMapper $exposureMapper;

    private ReceivableFactMapper $receivableMapper;

    private AllocationFactMapper $allocationMapper;

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

        $support = new AccountingFactSupport($this->codec);
        $this->cashMapper = new ActualCashFactMapper($support);
        $this->exposureMapper = new NoncashExposureFactMapper($support);
        $this->receivableMapper = new ReceivableFactMapper($support);
        $this->allocationMapper = new AllocationFactMapper($support);
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
            'refund.succeeded.v1' => $this->cashMapper->map($record),

            'exposure.over_refund',
            'exposure.over_refund.v1',
            'exposure.over_capture',
            'exposure.over_capture.v1',
            'exposure.discrepancy',
            'exposure.discrepancy.v1' => $this->exposureMapper->map($record),

            'invoice.issued',
            'invoice.issued.v1' => $this->receivableMapper->mapInvoiceIssued($record),

            'credit_note.issued',
            'credit_note.issued.v1' => $this->receivableMapper->mapCreditNoteIssued($record),

            'write_off.recorded',
            'write_off.recorded.v1',
            'invoice.uncollectible.v1' => $this->receivableMapper->mapWriteOffRecorded($record),

            'allocation.applied',
            'allocation.applied.v1',
            'allocation.reversed',
            'allocation.reversed.v1' => $this->allocationMapper->map($record),

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
            'occurredAt' => AccountingFactSupport::utc($fact->occurredAt),
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
}
