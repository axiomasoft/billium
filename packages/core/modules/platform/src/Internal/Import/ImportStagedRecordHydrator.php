<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Import;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Platform\Contracts\Import\ImportIdentityStore;
use Billium\Platform\Contracts\Import\ImportMonetaryPort;
use Billium\Platform\Data\Import\ImportApplyOptions;
use Billium\Platform\Data\Import\ImportEvidenceRef;
use Billium\Platform\Data\Import\ImportObservationOutcome;
use Billium\Platform\Data\Import\ImportReceiptStatus;
use Billium\Platform\Data\Import\ImportRecordApplyOutcome;
use Billium\Platform\Data\Import\ImportRecordApplyStatus;
use Billium\Platform\Data\Import\ImportRecordInput;
use Billium\Platform\Data\Import\ImportRecordKind;
use Billium\Platform\Data\Import\ImportSourceIdentity;
use Billium\Platform\Data\Import\ImportValidationStatus;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;

/**
 * Staged import row hydration, receipt checkpointing, and bounded row helpers (P9.3, D71).
 */
#[Internal]
final readonly class ImportStagedRecordHydrator
{
    /**
     * @param  Closure(): ImportMonetaryPort  $monetaryPort
     */
    public function __construct(
        private DatabaseManager $databases,
        private BilliumOptions $options,
        private ImportIdentityStore $store,
        private ImportObservationEnvelopeBuilder $envelopeBuilder,
        private Closure $monetaryPort,
        private Codec $codec,
    ) {}

    public function connection(): Connection
    {
        return $this->databases->connection($this->options->connection);
    }

    public function countRemainingWork(int $batchPk, ImportApplyOptions $options): int
    {
        $rows = self::objectRows($this->connection()->select('SELECT * FROM public.billium_import_record WHERE batch_pk = ? ORDER BY id ASC', [$batchPk]));
        $remaining = 0;
        foreach ($rows as $row) {
            $valStatus = self::strField($row, 'validation_status');
            if (in_array($valStatus, ['already_applied', 'rejected', 'conflict'], true)) {
                continue;
            }
            $record = $this->hydrateInputFromStagedRow($row);
            $recordPk = self::intField($row, 'id');
            $identity = $record->identity;
            $revision = $record->sourceRecordRevision;

            if ($valStatus === ImportValidationStatus::UnanchoredEvidence->value || $identity->recordKind === ImportRecordKind::UnanchoredEvidence) {
                if ($this->findBatchRecordReceipt($batchPk, $recordPk, ImportReceiptStatus::UnanchoredEvidence) === null) {
                    $remaining++;
                }

                continue;
            }

            $mapping = $this->store->findMapping($identity, $revision);
            if ($mapping === null) {
                $remaining++;

                continue;
            }
            if (in_array($identity->recordKind, [ImportRecordKind::ProvenPaymentEvidence, ImportRecordKind::ProvenRefundEvidence], true)) {
                $receipt = $this->store->findAppliedReceipt($identity, $revision);
                if ($receipt === null) {
                    $remaining++;
                } elseif ($mapping->billiumTargetRef === null && $options->adoptReceipts) {
                    $opKind = $identity->recordKind === ImportRecordKind::ProvenRefundEvidence ? 'refund' : 'charge';
                    $opRef = $this->envelopeBuilder->operationRef($record);
                    $obs = ($this->monetaryPort)()->findObservationOutcome($options->migrationSourceAuthority, $opRef, $opKind, max(1, $revision));
                    if ($obs->status === ImportObservationOutcome::STATUS_APPLIED || $obs->status === ImportObservationOutcome::STATUS_ABSENT) {
                        $remaining++;
                    }
                }
            } elseif ($mapping->billiumTargetRef === null) {
                $remaining++;
            }
        }

        return $remaining;
    }

    /**
     * @param  list<object>  $obligationRows
     * @return array<string, string>
     */
    public function collectKnownObligationRefs(array $obligationRows): array
    {
        $map = [];
        foreach ($obligationRows as $row) {
            $record = $this->hydrateInputFromStagedRow($row);
            $oblRef = self::boundedRef(
                self::nonEmptyString($record->originalBusinessRefs['obligationRef'] ?? null) ?? ('imp-obl:'.$record->identity->sourceRecordId),
                'imp-obl',
            );
            $map[$record->identity->sourceRecordId] = $oblRef;
            $map[$oblRef] = $oblRef;
        }

        return $map;
    }

    /**
     * @param  list<object>  $paymentRows
     * @return array<string, string>
     */
    public function collectKnownChargeOperationRefs(array $paymentRows): array
    {
        $map = [];
        foreach ($paymentRows as $row) {
            $record = $this->hydrateInputFromStagedRow($row);
            $opRef = $this->envelopeBuilder->operationRef($record);
            $map[$record->identity->sourceRecordId] = $opRef;
            $map[$opRef] = $opRef;
            $payRef = self::nonEmptyString($record->originalBusinessRefs['paymentRef'] ?? null);
            if ($payRef !== null) {
                $map[$payRef] = $opRef;
            }
            $pspRef = self::nonEmptyString($record->fundingRefs['providerOperationRef'] ?? null);
            if ($pspRef !== null) {
                $map[$pspRef] = $opRef;
            }
        }

        return $map;
    }

    public function recordTerminalValidationReceipt(
        int $batchPk,
        object $row,
        ImportReceiptStatus $receiptStatus,
        ImportRecordApplyStatus $applyStatus,
    ): ImportRecordApplyOutcome {
        $record = $this->hydrateInputFromStagedRow($row);
        $recordPk = self::intField($row, 'id');
        $factRef = $record->sourceFactRef ?? $record->identity->stableSourceFactRef($record->sourceRecordRevision, $this->codec);
        $receiptRef = self::boundedRef('imp-rcpt:'.$receiptStatus->value.':'.$batchPk.':'.$recordPk, 'imp-rcpt');

        $existingRef = $this->findBatchRecordReceipt($batchPk, $recordPk, $receiptStatus);
        if ($existingRef === null) {
            $this->store->recordReceipt(
                batchPk: $batchPk,
                receiptRef: $receiptRef,
                identity: $record->identity,
                sourceRecordRevision: $record->sourceRecordRevision,
                payloadDigest: $record->payloadDigest,
                status: $receiptStatus,
                recordPk: $recordPk,
                sourceFactRef: $factRef,
                details: [
                    'rejectionCode' => self::optStrField($row, 'rejection_code'),
                ],
            );
        } else {
            $receiptRef = $existingRef;
        }

        return new ImportRecordApplyOutcome(
            identity: $record->identity,
            sourceRecordRevision: $record->sourceRecordRevision,
            sourceFactRef: $factRef,
            status: $applyStatus,
            importReceiptRef: $receiptRef,
            detail: self::optStrField($row, 'rejection_code'),
        );
    }

    public function findBatchRecordReceipt(int $batchPk, int $recordPk, ImportReceiptStatus $status): ?string
    {
        $row = $this->connection()->selectOne(
            'SELECT receipt_ref FROM public.billium_import_receipt WHERE batch_pk = ? AND record_pk = ? AND status = ? LIMIT 1',
            [$batchPk, $recordPk, $status->value],
        );

        return is_object($row) ? self::strField($row, 'receipt_ref') : null;
    }

    public function markStagedRecordAlreadyApplied(int $recordPk, string $sourceFactRef): void
    {
        $this->connection()->update(
            "UPDATE public.billium_import_record SET validation_status = 'already_applied', source_fact_ref = ? WHERE id = ?",
            [$sourceFactRef, $recordPk],
        );
    }

    public function markStagedRecordConflict(int $recordPk): void
    {
        $this->connection()->update(
            "UPDATE public.billium_import_record SET validation_status = 'conflict' WHERE id = ?",
            [$recordPk],
        );
    }

    public function hydrateInputFromStagedRow(object $row): ImportRecordInput
    {
        $payload = self::decodeArray(self::strField($row, 'payload'));
        $currency = self::optStrField($row, 'currency');
        $amountMinor = self::optIntField($row, 'amount_minor');
        $money = $currency !== null && $amountMinor !== null ? new Money($amountMinor, Currency::of($currency)) : null;

        /** @var array<string, string> $origRefs */
        $origRefs = [];
        if (is_array($payload['originalBusinessRefs'] ?? null)) {
            foreach ($payload['originalBusinessRefs'] as $k => $v) {
                if (is_string($v)) {
                    $origRefs[(string) $k] = $v;
                }
            }
        }

        $evidenceRefs = [];
        if (is_array($payload['evidenceRefs'] ?? null)) {
            foreach ($payload['evidenceRefs'] as $ev) {
                if (is_array($ev) && is_string($ev['evidenceRef'] ?? null) && is_string($ev['kind'] ?? null) && is_string($ev['digest'] ?? null)) {
                    $evidenceRefs[] = new ImportEvidenceRef(
                        evidenceRef: $ev['evidenceRef'],
                        kind: $ev['kind'],
                        digest: $ev['digest'],
                        sanitized: ($ev['sanitized'] ?? true) === true,
                    );
                }
            }
        }

        $missingEvidence = [];
        foreach (self::decodeList(self::strField($row, 'missing_evidence')) as $item) {
            if (is_string($item)) {
                $missingEvidence[] = $item;
            }
        }

        $batchRow = $this->connection()->selectOne(
            'SELECT batch_id, source_export_revision, export_cutoff, manifest_digest FROM public.billium_import_batch WHERE id = ?',
            [self::intField($row, 'batch_pk')],
        ) ?? throw new LogicException('Staged record batch row missing');

        return new ImportRecordInput(
            identity: new ImportSourceIdentity(
                installationRef: self::strField($row, 'installation_ref'),
                environment: self::strField($row, 'environment'),
                sellerRef: self::strField($row, 'seller_ref'),
                sourceSystem: self::strField($row, 'source_system'),
                sourceInstallation: self::strField($row, 'source_installation'),
                sourceBook: self::strField($row, 'source_book'),
                sourceRecordId: self::strField($row, 'source_record_id'),
                recordKind: ImportRecordKind::from(self::strField($row, 'record_kind')),
            ),
            batchId: self::strField($batchRow, 'batch_id'),
            sourceExportRevision: self::intField($batchRow, 'source_export_revision'),
            exportCutoff: new DateTimeImmutable(self::strField($batchRow, 'export_cutoff')),
            manifestDigest: self::strField($batchRow, 'manifest_digest'),
            sourceRecordRevision: self::intField($row, 'source_record_revision'),
            eventBusinessDate: new DateTimeImmutable(self::strField($row, 'event_business_date')),
            payloadDigest: self::strField($row, 'payload_digest'),
            originalBusinessRefs: $origRefs,
            evidenceRefs: $evidenceRefs,
            money: $money,
            frozenPriceSnapshot: is_array($payload['frozenPriceSnapshot'] ?? null) ? self::stringKeyed($payload['frozenPriceSnapshot']) : null,
            fundingRefs: is_array($payload['fundingRefs'] ?? null) ? self::stringKeyed($payload['fundingRefs']) : null,
            legalDocument: is_array($payload['legalDocument'] ?? null) ? self::stringKeyed($payload['legalDocument']) : null,
            importPolicyVersion: self::optStrField($row, 'import_policy_version'),
            sourceFactRef: self::optStrField($row, 'source_fact_ref'),
            missingEvidence: $missingEvidence,
            payload: $payload,
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function stringKeyed(array $raw): array
    {
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function decodeArray(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? self::stringKeyed($decoded) : [];
    }

    /** @return list<mixed> */
    public static function decodeList(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }

    public static function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' || str_contains($trimmed, "\0") ? null : $trimmed;
    }

    public static function boundedRef(string $value, string $prefix): string
    {
        if (strlen($value) <= 200) {
            return $value;
        }

        return $prefix.':'.hash('sha256', $value);
    }

    public static function strField(mixed $row, string $column): string
    {
        $value = is_object($row) ? (get_object_vars($row)[$column] ?? null) : null;
        if (! is_string($value)) {
            throw new LogicException("Expected string column {$column}");
        }

        return $value;
    }

    public static function optStrField(mixed $row, string $column): ?string
    {
        $value = is_object($row) ? (get_object_vars($row)[$column] ?? null) : null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new LogicException("Expected nullable string column {$column}");
        }

        return $value;
    }

    public static function intField(mixed $row, string $column): int
    {
        $value = is_object($row) ? (get_object_vars($row)[$column] ?? null) : null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            return (int) $value;
        }

        throw new LogicException("Expected integer column {$column}");
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<object>
     */
    public static function objectRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_object($row)) {
                throw new LogicException('Expected stdClass row from database select');
            }
            $out[] = $row;
        }

        return $out;
    }

    public static function scalarInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            return (int) $value;
        }

        return 0;
    }

    public static function optIntField(mixed $row, string $column): ?int
    {
        $value = is_object($row) ? (get_object_vars($row)[$column] ?? null) : null;
        if ($value === null) {
            return null;
        }

        return self::intField($row, $column);
    }
}
