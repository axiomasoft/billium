<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Import;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Platform\Contracts\Import\ImportIdentityStore;
use Billium\Platform\Data\Import\ImportBatchStatus;
use Billium\Platform\Data\Import\ImportManifest;
use Billium\Platform\Data\Import\ImportMappingRecord;
use Billium\Platform\Data\Import\ImportMappingRegistrationResult;
use Billium\Platform\Data\Import\ImportMappingStatus;
use Billium\Platform\Data\Import\ImportReceiptRecord;
use Billium\Platform\Data\Import\ImportReceiptStatus;
use Billium\Platform\Data\Import\ImportRecordInput;
use Billium\Platform\Data\Import\ImportRecordKind;
use Billium\Platform\Data\Import\ImportRecordValidationResult;
use Billium\Platform\Data\Import\ImportSourceIdentity;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use LogicException;
use Override;
use Packman\Attributes\Internal;
use stdClass;

/**
 * PostgreSQL-backed staging, source identity mapping, tombstone and append-only receipt store for external.import.v1.
 */
#[Internal]
final readonly class DatabaseImportIdentityStore implements ImportIdentityStore
{
    public function __construct(
        private DatabaseManager $databases,
        private BilliumOptions $options,
        private Codec $codec = new Codec,
    ) {}

    #[Override]
    public function stageBatch(ImportManifest $manifest): int
    {
        $connection = $this->connection();
        $existing = $connection->selectOne(
            'SELECT id, manifest_digest, records_checksum, export_cutoff FROM public.billium_import_batch '
            .'WHERE installation_ref = ? AND environment = ? AND seller_ref = ? AND batch_id = ?',
            [$manifest->installationRef, $manifest->environment, $manifest->sellerRef, $manifest->batchId],
        );
        if (is_object($existing)) {
            $existingDigest = self::strField($existing, 'manifest_digest');
            $existingChecksum = self::strField($existing, 'records_checksum');
            if ($existingDigest !== $manifest->manifestDigest || $existingChecksum !== $manifest->recordsChecksum) {
                throw new ProblemException(
                    ProblemCode::IdempotencyConflict,
                    'Import batch already staged with a different immutable manifest digest or records checksum',
                );
            }

            return self::intField($existing, 'id');
        }

        $payload = array_merge($manifest->toWire(), $manifest->rawPayload);
        $row = $connection->selectOne(
            'INSERT INTO public.billium_import_batch ('
            .'installation_ref, environment, seller_ref, batch_id, source_system, source_installation, source_book, '
            .'source_export_revision, export_cutoff, manifest_digest, records_checksum, record_count, '
            .'import_policy_version, status, sanitization_policy, manifest_payload'
            .') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb) RETURNING id',
            [
                $manifest->installationRef,
                $manifest->environment,
                $manifest->sellerRef,
                $manifest->batchId,
                $manifest->sourceSystem,
                $manifest->sourceInstallation,
                $manifest->sourceBook,
                $manifest->sourceExportRevision,
                $manifest->exportCutoff->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                $manifest->manifestDigest,
                $manifest->recordsChecksum,
                $manifest->recordCount,
                $manifest->importPolicyVersion,
                ImportBatchStatus::Staged->value,
                json_encode($manifest->sanitizationPolicy, JSON_THROW_ON_ERROR),
                json_encode($payload, JSON_THROW_ON_ERROR),
            ],
        );

        return self::intField($row, 'id');
    }

    #[Override]
    public function stageRecord(int $batchPk, ImportRecordInput $record, ImportRecordValidationResult $validation): int
    {
        $connection = $this->connection();
        $identity = $record->identity;
        $identityDigest = $identity->identityDigest($this->codec);
        $existing = $connection->selectOne(
            'SELECT id, payload_digest FROM public.billium_import_record '
            .'WHERE batch_pk = ? AND installation_ref = ? AND environment = ? AND seller_ref = ? '
            .'AND source_installation = ? AND source_book = ? AND source_record_id = ? '
            .'AND record_kind = ? AND source_record_revision = ?',
            [
                $batchPk,
                $identity->installationRef,
                $identity->environment,
                $identity->sellerRef,
                $identity->sourceInstallation,
                $identity->sourceBook,
                $identity->sourceRecordId,
                $identity->recordKind->value,
                $record->sourceRecordRevision,
            ],
        );
        if (is_object($existing)) {
            if (self::strField($existing, 'payload_digest') !== $record->payloadDigest) {
                throw new ProblemException(
                    ProblemCode::IdempotencyConflict,
                    'Staged import record already exists in this batch with a different payload digest',
                );
            }

            return self::intField($existing, 'id');
        }

        $row = $connection->selectOne(
            'INSERT INTO public.billium_import_record ('
            .'batch_pk, installation_ref, environment, seller_ref, source_system, source_installation, source_book, '
            .'source_record_id, source_record_revision, record_kind, source_identity_digest, payload_digest, '
            .'event_business_date, currency, amount_minor, source_fact_ref, import_policy_version, '
            .'validation_status, reason_codes, missing_evidence, rejection_code, payload'
            .') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?, ?::jsonb) RETURNING id',
            [
                $batchPk,
                $identity->installationRef,
                $identity->environment,
                $identity->sellerRef,
                $identity->sourceSystem,
                $identity->sourceInstallation,
                $identity->sourceBook,
                $identity->sourceRecordId,
                $record->sourceRecordRevision,
                $identity->recordKind->value,
                $identityDigest,
                $record->payloadDigest,
                $record->eventBusinessDate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                $record->money?->currency->code,
                $record->money?->minor,
                $validation->sourceFactRef ?? $record->sourceFactRef,
                $record->importPolicyVersion,
                $validation->status->value,
                json_encode($validation->reasonCodes, JSON_THROW_ON_ERROR),
                json_encode($validation->missingEvidence, JSON_THROW_ON_ERROR),
                $validation->rejectionCode,
                json_encode($record->payload !== [] ? $record->payload : ['sourceRecordId' => $identity->sourceRecordId], JSON_THROW_ON_ERROR),
            ],
        );

        return self::intField($row, 'id');
    }

    #[Override]
    public function transitionBatch(
        int $batchPk,
        ImportBatchStatus $targetStatus,
        ?string $approvalRef = null,
        ?string $authorityFenceRef = null,
    ): void {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        $sets = ['status = ?'];
        $bindings = [$targetStatus->value];

        if ($targetStatus === ImportBatchStatus::Validated) {
            $sets[] = 'validated_at = COALESCE(validated_at, ?)';
            $bindings[] = $now;
        }
        if ($targetStatus === ImportBatchStatus::Approved) {
            $sets[] = 'approved_at = COALESCE(approved_at, ?)';
            $bindings[] = $now;
        }
        if ($targetStatus === ImportBatchStatus::Completed) {
            $sets[] = 'completed_at = COALESCE(completed_at, ?)';
            $bindings[] = $now;
        }
        if ($targetStatus === ImportBatchStatus::Rejected) {
            $sets[] = 'rejected_at = COALESCE(rejected_at, ?)';
            $bindings[] = $now;
        }
        if ($approvalRef !== null) {
            $sets[] = 'approval_ref = ?';
            $bindings[] = $approvalRef;
        }
        if ($authorityFenceRef !== null) {
            $sets[] = 'authority_fence_ref = ?';
            $bindings[] = $authorityFenceRef;
        }
        $bindings[] = $batchPk;

        $updated = $this->connection()->update(
            'UPDATE public.billium_import_batch SET '.implode(', ', $sets).' WHERE id = ?',
            $bindings,
        );
        if ($updated !== 1) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, "Unknown import batch {$batchPk}");
        }
    }

    #[Override]
    public function registerMapping(
        ImportSourceIdentity $identity,
        int $sourceRecordRevision,
        string $payloadDigest,
        string $sourceFactRef,
        ?string $originalIssuer = null,
        ?string $originalSourceNumber = null,
        ?string $importPolicyVersion = null,
    ): ImportMappingRegistrationResult {
        self::assertDigest($payloadDigest);
        if ($sourceRecordRevision < 0) {
            throw new InvalidArgumentException('sourceRecordRevision must be non-negative');
        }

        $connection = $this->connection();
        $identityDigest = $identity->identityDigest($this->codec);

        $tombstoned = $connection->selectOne(
            'SELECT * FROM public.billium_import_mapping '
            .'WHERE installation_ref = ? AND environment = ? AND seller_ref = ? '
            .'AND source_installation = ? AND source_book = ? AND source_record_id = ? AND record_kind = ? '
            ."AND status = 'tombstoned' ORDER BY source_record_revision DESC LIMIT 1",
            [
                $identity->installationRef,
                $identity->environment,
                $identity->sellerRef,
                $identity->sourceInstallation,
                $identity->sourceBook,
                $identity->sourceRecordId,
                $identity->recordKind->value,
            ],
        );
        if (is_object($tombstoned)) {
            return new ImportMappingRegistrationResult(
                outcome: ImportMappingRegistrationResult::TOMBSTONED_CONFLICT,
                mapping: self::hydrateMapping($tombstoned),
                conflictReason: 'source_identity_tombstoned',
            );
        }

        $existingSameRev = $this->findMapping($identity, $sourceRecordRevision);
        if ($existingSameRev !== null) {
            if ($existingSameRev->payloadDigest === $payloadDigest) {
                return new ImportMappingRegistrationResult(
                    outcome: ImportMappingRegistrationResult::DUPLICATE,
                    mapping: $existingSameRev,
                );
            }

            return new ImportMappingRegistrationResult(
                outcome: ImportMappingRegistrationResult::CONFLICT,
                mapping: $existingSameRev,
                conflictReason: 'payload_digest_mismatch',
            );
        }

        $existingActive = $this->findMapping($identity);
        if ($existingActive !== null) {
            if ($existingActive->sourceRecordRevision === $sourceRecordRevision) {
                return $existingActive->payloadDigest === $payloadDigest
                    ? new ImportMappingRegistrationResult(ImportMappingRegistrationResult::DUPLICATE, $existingActive)
                    : new ImportMappingRegistrationResult(ImportMappingRegistrationResult::CONFLICT, $existingActive, 'payload_digest_mismatch');
            }

            return new ImportMappingRegistrationResult(
                outcome: ImportMappingRegistrationResult::CONFLICT,
                mapping: $existingActive,
                conflictReason: 'active_mapping_exists_use_supersede_for_correction',
            );
        }

        $mappingRef = 'imp-map:'.substr(hash('sha256', $identityDigest.':'.$sourceRecordRevision), 0, 32);
        $row = $connection->selectOne(
            'INSERT INTO public.billium_import_mapping ('
            .'mapping_ref, installation_ref, environment, seller_ref, source_system, source_installation, source_book, '
            .'source_record_id, record_kind, source_record_revision, source_identity_digest, payload_digest, '
            .'source_fact_ref, original_issuer, original_source_number, import_policy_version, status'
            .') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING RETURNING *',
            [
                $mappingRef,
                $identity->installationRef,
                $identity->environment,
                $identity->sellerRef,
                $identity->sourceSystem,
                $identity->sourceInstallation,
                $identity->sourceBook,
                $identity->sourceRecordId,
                $identity->recordKind->value,
                $sourceRecordRevision,
                $identityDigest,
                $payloadDigest,
                $sourceFactRef,
                $originalIssuer,
                $originalSourceNumber,
                $importPolicyVersion,
                ImportMappingStatus::Active->value,
            ],
        );

        if (! is_object($row)) {
            $racedSameRev = $this->findMapping($identity, $sourceRecordRevision);
            if ($racedSameRev !== null) {
                if ($racedSameRev->status === ImportMappingStatus::Tombstoned) {
                    return new ImportMappingRegistrationResult(
                        outcome: ImportMappingRegistrationResult::TOMBSTONED_CONFLICT,
                        mapping: $racedSameRev,
                        conflictReason: 'source_identity_tombstoned',
                    );
                }

                return $racedSameRev->payloadDigest === $payloadDigest
                    ? new ImportMappingRegistrationResult(ImportMappingRegistrationResult::DUPLICATE, $racedSameRev)
                    : new ImportMappingRegistrationResult(ImportMappingRegistrationResult::CONFLICT, $racedSameRev, 'payload_digest_mismatch');
            }

            $racedActive = $this->findMapping($identity);
            if ($racedActive !== null) {
                return new ImportMappingRegistrationResult(
                    outcome: ImportMappingRegistrationResult::CONFLICT,
                    mapping: $racedActive,
                    conflictReason: 'active_mapping_exists_use_supersede_for_correction',
                );
            }

            throw new ProblemException(ProblemCode::IdempotencyConflict, 'Concurrent import mapping registration conflict');
        }

        return new ImportMappingRegistrationResult(
            outcome: ImportMappingRegistrationResult::CREATED,
            mapping: self::hydrateMapping($row),
        );
    }

    #[Override]
    public function findMapping(ImportSourceIdentity $identity, ?int $sourceRecordRevision = null): ?ImportMappingRecord
    {
        $connection = $this->connection();
        if ($sourceRecordRevision !== null) {
            $row = $connection->selectOne(
                'SELECT * FROM public.billium_import_mapping '
                .'WHERE installation_ref = ? AND environment = ? AND seller_ref = ? '
                .'AND source_installation = ? AND source_book = ? AND source_record_id = ? '
                .'AND record_kind = ? AND source_record_revision = ?',
                [
                    $identity->installationRef,
                    $identity->environment,
                    $identity->sellerRef,
                    $identity->sourceInstallation,
                    $identity->sourceBook,
                    $identity->sourceRecordId,
                    $identity->recordKind->value,
                    $sourceRecordRevision,
                ],
            );

            return is_object($row) ? self::hydrateMapping($row) : null;
        }

        $row = $connection->selectOne(
            'SELECT * FROM public.billium_import_mapping '
            .'WHERE installation_ref = ? AND environment = ? AND seller_ref = ? '
            .'AND source_installation = ? AND source_book = ? AND source_record_id = ? AND record_kind = ? '
            ."ORDER BY (status = 'active') DESC, source_record_revision DESC LIMIT 1",
            [
                $identity->installationRef,
                $identity->environment,
                $identity->sellerRef,
                $identity->sourceInstallation,
                $identity->sourceBook,
                $identity->sourceRecordId,
                $identity->recordKind->value,
            ],
        );

        return is_object($row) ? self::hydrateMapping($row) : null;
    }

    #[Override]
    public function assignMappingTarget(
        ImportSourceIdentity $identity,
        int $sourceRecordRevision,
        string $targetKind,
        string $targetRef,
    ): ImportMappingRecord {
        if (trim($targetKind) === '' || strlen($targetKind) > 100 || str_contains($targetKind, "\0")) {
            throw new InvalidArgumentException('targetKind must be a bounded nonempty string');
        }
        if (trim($targetRef) === '' || strlen($targetRef) > 200 || str_contains($targetRef, "\0")) {
            throw new InvalidArgumentException('targetRef must be a bounded nonempty string');
        }

        $existing = $this->findMapping($identity, $sourceRecordRevision);
        if ($existing === null) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'Import mapping not found for target assignment');
        }
        if ($existing->billiumTargetRef !== null) {
            if ($existing->billiumTargetKind === $targetKind && $existing->billiumTargetRef === $targetRef) {
                return $existing;
            }

            throw new ProblemException(
                ProblemCode::IdempotencyConflict,
                'Import mapping target binding is immutable once assigned',
            );
        }

        $row = $this->connection()->selectOne(
            'UPDATE public.billium_import_mapping '
            .'SET billium_target_kind = ?, billium_target_ref = ? '
            .'WHERE id = ? AND installation_ref = ? AND environment = ? AND seller_ref = ? '
            .'RETURNING *',
            [
                $targetKind,
                $targetRef,
                $existing->id,
                $identity->installationRef,
                $identity->environment,
                $identity->sellerRef,
            ],
        );
        if (! is_object($row)) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'Import mapping vanished during target assignment');
        }

        return self::hydrateMapping($row);
    }

    #[Override]
    public function supersedeMapping(
        ImportSourceIdentity $identity,
        int $previousRevision,
        int $newRevision,
        string $newPayloadDigest,
        string $newSourceFactRef,
        ?string $originalIssuer = null,
        ?string $originalSourceNumber = null,
        ?string $importPolicyVersion = null,
    ): ImportMappingRecord {
        self::assertDigest($newPayloadDigest);
        if ($newRevision <= $previousRevision) {
            throw new InvalidArgumentException('Correction revision must be strictly greater than previousRevision');
        }

        $connection = $this->connection();

        return $connection->transaction(function () use (
            $connection,
            $identity,
            $previousRevision,
            $newRevision,
            $newPayloadDigest,
            $newSourceFactRef,
            $originalIssuer,
            $originalSourceNumber,
            $importPolicyVersion,
        ): ImportMappingRecord {
            $updated = $connection->update(
                'UPDATE public.billium_import_mapping SET status = ?, superseded_by_revision = ? '
                .'WHERE installation_ref = ? AND environment = ? AND seller_ref = ? '
                .'AND source_installation = ? AND source_book = ? AND source_record_id = ? '
                .'AND record_kind = ? AND source_record_revision = ? AND status = ?',
                [
                    ImportMappingStatus::Superseded->value,
                    $newRevision,
                    $identity->installationRef,
                    $identity->environment,
                    $identity->sellerRef,
                    $identity->sourceInstallation,
                    $identity->sourceBook,
                    $identity->sourceRecordId,
                    $identity->recordKind->value,
                    $previousRevision,
                    ImportMappingStatus::Active->value,
                ],
            );
            if ($updated !== 1) {
                throw new ProblemException(
                    ProblemCode::IdempotencyConflict,
                    'Cannot supersede import mapping: active previous revision not found',
                );
            }

            $identityDigest = $identity->identityDigest($this->codec);
            $mappingRef = 'imp-map:'.substr(hash('sha256', $identityDigest.':'.$newRevision), 0, 32);
            $row = $connection->selectOne(
                'INSERT INTO public.billium_import_mapping ('
                .'mapping_ref, installation_ref, environment, seller_ref, source_system, source_installation, source_book, '
                .'source_record_id, record_kind, source_record_revision, source_identity_digest, payload_digest, '
                .'source_fact_ref, original_issuer, original_source_number, import_policy_version, status'
                .') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING *',
                [
                    $mappingRef,
                    $identity->installationRef,
                    $identity->environment,
                    $identity->sellerRef,
                    $identity->sourceSystem,
                    $identity->sourceInstallation,
                    $identity->sourceBook,
                    $identity->sourceRecordId,
                    $identity->recordKind->value,
                    $newRevision,
                    $identityDigest,
                    $newPayloadDigest,
                    $newSourceFactRef,
                    $originalIssuer,
                    $originalSourceNumber,
                    $importPolicyVersion,
                    ImportMappingStatus::Active->value,
                ],
            );

            return self::hydrateMapping($row);
        });
    }

    #[Override]
    public function tombstoneMapping(
        ImportSourceIdentity $identity,
        int $sourceRecordRevision,
        string $reason,
    ): ImportMappingRecord {
        if (trim($reason) === '' || strlen($reason) > 200) {
            throw new InvalidArgumentException('Tombstone reason must be a bounded nonempty string');
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        $row = $this->connection()->selectOne(
            'UPDATE public.billium_import_mapping '
            .'SET status = ?, tombstone_reason = ?, tombstoned_at = ? '
            .'WHERE installation_ref = ? AND environment = ? AND seller_ref = ? '
            .'AND source_installation = ? AND source_book = ? AND source_record_id = ? '
            .'AND record_kind = ? AND source_record_revision = ? '
            .'RETURNING *',
            [
                ImportMappingStatus::Tombstoned->value,
                $reason,
                $now,
                $identity->installationRef,
                $identity->environment,
                $identity->sellerRef,
                $identity->sourceInstallation,
                $identity->sourceBook,
                $identity->sourceRecordId,
                $identity->recordKind->value,
                $sourceRecordRevision,
            ],
        );
        if (! is_object($row)) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'Import mapping not found for tombstone');
        }

        return self::hydrateMapping($row);
    }

    #[Override]
    public function recordReceipt(
        int $batchPk,
        string $receiptRef,
        ImportSourceIdentity $identity,
        int $sourceRecordRevision,
        string $payloadDigest,
        ImportReceiptStatus $status,
        ?int $recordPk = null,
        ?int $mappingPk = null,
        ?string $sourceFactRef = null,
        ?string $billiumReceiptRef = null,
        array $details = [],
    ): ImportReceiptRecord {
        self::assertDigest($payloadDigest);
        $identityDigest = $identity->identityDigest($this->codec);
        $row = $this->connection()->selectOne(
            'INSERT INTO public.billium_import_receipt ('
            .'receipt_ref, installation_ref, environment, seller_ref, batch_pk, record_pk, mapping_pk, '
            .'source_identity_digest, source_record_revision, payload_digest, source_fact_ref, '
            .'status, billium_receipt_ref, details'
            .') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb) ON CONFLICT DO NOTHING RETURNING *',
            [
                $receiptRef,
                $identity->installationRef,
                $identity->environment,
                $identity->sellerRef,
                $batchPk,
                $recordPk,
                $mappingPk,
                $identityDigest,
                $sourceRecordRevision,
                $payloadDigest,
                $sourceFactRef,
                $status->value,
                $billiumReceiptRef,
                json_encode($details === [] ? new stdClass : $details, JSON_THROW_ON_ERROR),
            ],
        );

        if (! is_object($row)) {
            $byRef = $this->connection()->selectOne(
                'SELECT * FROM public.billium_import_receipt '
                .'WHERE installation_ref = ? AND environment = ? AND seller_ref = ? AND receipt_ref = ?',
                [$identity->installationRef, $identity->environment, $identity->sellerRef, $receiptRef],
            );
            if (is_object($byRef)) {
                $existingByRef = self::hydrateReceipt($byRef);
                if ($existingByRef->payloadDigest === $payloadDigest) {
                    return $existingByRef;
                }
            }
            if (in_array($status, [ImportReceiptStatus::Applied, ImportReceiptStatus::ObservationQueued], true)) {
                $existingApplied = $this->findAppliedReceipt($identity, $sourceRecordRevision);
                if ($existingApplied !== null && $existingApplied->payloadDigest === $payloadDigest) {
                    return $existingApplied;
                }
            }

            throw new ProblemException(ProblemCode::IdempotencyConflict, 'Concurrent import receipt conflict');
        }

        return self::hydrateReceipt($row);
    }

    #[Override]
    public function findAppliedReceipt(ImportSourceIdentity $identity, int $sourceRecordRevision): ?ImportReceiptRecord
    {
        $identityDigest = $identity->identityDigest($this->codec);
        $row = $this->connection()->selectOne(
            'SELECT * FROM public.billium_import_receipt '
            .'WHERE installation_ref = ? AND environment = ? AND seller_ref = ? '
            .'AND source_identity_digest = ? AND source_record_revision = ? '
            ."AND status IN ('applied', 'observation_queued') "
            .'ORDER BY id ASC LIMIT 1',
            [
                $identity->installationRef,
                $identity->environment,
                $identity->sellerRef,
                $identityDigest,
                $sourceRecordRevision,
            ],
        );

        return is_object($row) ? self::hydrateReceipt($row) : null;
    }

    private function connection(): Connection
    {
        return $this->databases->connection($this->options->connection);
    }

    private static function assertDigest(string $digest): void
    {
        if (preg_match('/^sha256:[0-9a-f]{64}$/', $digest) !== 1) {
            throw new InvalidArgumentException('Digest must be a valid sha256 digest');
        }
    }

    private static function hydrateMapping(mixed $row): ImportMappingRecord
    {
        if (! is_object($row)) {
            throw new LogicException('Expected import mapping row');
        }

        $tombstonedAt = self::optStrField($row, 'tombstoned_at');

        return new ImportMappingRecord(
            id: self::intField($row, 'id'),
            mappingRef: self::strField($row, 'mapping_ref'),
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
            sourceRecordRevision: self::intField($row, 'source_record_revision'),
            sourceIdentityDigest: self::strField($row, 'source_identity_digest'),
            payloadDigest: self::strField($row, 'payload_digest'),
            sourceFactRef: self::strField($row, 'source_fact_ref'),
            status: ImportMappingStatus::from(self::strField($row, 'status')),
            billiumTargetKind: self::optStrField($row, 'billium_target_kind'),
            billiumTargetRef: self::optStrField($row, 'billium_target_ref'),
            originalIssuer: self::optStrField($row, 'original_issuer'),
            originalSourceNumber: self::optStrField($row, 'original_source_number'),
            importPolicyVersion: self::optStrField($row, 'import_policy_version'),
            tombstoneReason: self::optStrField($row, 'tombstone_reason'),
            tombstonedAt: $tombstonedAt !== null ? new DateTimeImmutable($tombstonedAt) : null,
            supersededByRevision: self::optIntField($row, 'superseded_by_revision'),
        );
    }

    private static function hydrateReceipt(mixed $row): ImportReceiptRecord
    {
        if (! is_object($row)) {
            throw new LogicException('Expected import receipt row');
        }
        $rawDetails = self::strField($row, 'details');
        $decoded = json_decode($rawDetails, true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $details */
        $details = is_array($decoded) ? $decoded : [];

        return new ImportReceiptRecord(
            id: self::intField($row, 'id'),
            receiptRef: self::strField($row, 'receipt_ref'),
            batchPk: self::intField($row, 'batch_pk'),
            recordPk: self::optIntField($row, 'record_pk'),
            mappingPk: self::optIntField($row, 'mapping_pk'),
            sourceIdentityDigest: self::strField($row, 'source_identity_digest'),
            sourceRecordRevision: self::intField($row, 'source_record_revision'),
            payloadDigest: self::strField($row, 'payload_digest'),
            sourceFactRef: self::optStrField($row, 'source_fact_ref'),
            status: ImportReceiptStatus::from(self::strField($row, 'status')),
            billiumReceiptRef: self::optStrField($row, 'billium_receipt_ref'),
            details: $details,
            recordedAt: new DateTimeImmutable(self::strField($row, 'recorded_at')),
        );
    }

    private static function strField(mixed $row, string $column): string
    {
        $value = is_object($row) ? (get_object_vars($row)[$column] ?? null) : null;
        if (! is_string($value)) {
            throw new LogicException("Expected string column {$column}");
        }

        return $value;
    }

    private static function optStrField(mixed $row, string $column): ?string
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

    private static function intField(mixed $row, string $column): int
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

    private static function optIntField(mixed $row, string $column): ?int
    {
        $value = is_object($row) ? (get_object_vars($row)[$column] ?? null) : null;
        if ($value === null) {
            return null;
        }

        return self::intField($row, $column);
    }
}
