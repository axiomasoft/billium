<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Import;

use Billium\Platform\Data\Import\ImportBatchStatus;
use Billium\Platform\Data\Import\ImportManifest;
use Billium\Platform\Data\Import\ImportMappingRecord;
use Billium\Platform\Data\Import\ImportMappingRegistrationResult;
use Billium\Platform\Data\Import\ImportReceiptRecord;
use Billium\Platform\Data\Import\ImportReceiptStatus;
use Billium\Platform\Data\Import\ImportRecordInput;
use Billium\Platform\Data\Import\ImportRecordValidationResult;
use Billium\Platform\Data\Import\ImportSourceIdentity;
use Packman\Attributes\Api;

/**
 * Platform-owned staging, source identity mapping, tombstone and append-only audit receipt store for external.import.v1.
 */
#[Api(since: '1.0')]
interface ImportIdentityStore
{
    public function stageBatch(ImportManifest $manifest): int;

    public function stageRecord(int $batchPk, ImportRecordInput $record, ImportRecordValidationResult $validation): int;

    public function transitionBatch(
        int $batchPk,
        ImportBatchStatus $targetStatus,
        ?string $approvalRef = null,
        ?string $authorityFenceRef = null,
    ): void;

    public function registerMapping(
        ImportSourceIdentity $identity,
        int $sourceRecordRevision,
        string $payloadDigest,
        string $sourceFactRef,
        ?string $originalIssuer = null,
        ?string $originalSourceNumber = null,
        ?string $importPolicyVersion = null,
    ): ImportMappingRegistrationResult;

    public function findMapping(ImportSourceIdentity $identity, ?int $sourceRecordRevision = null): ?ImportMappingRecord;

    public function assignMappingTarget(
        ImportSourceIdentity $identity,
        int $sourceRecordRevision,
        string $targetKind,
        string $targetRef,
    ): ImportMappingRecord;

    public function supersedeMapping(
        ImportSourceIdentity $identity,
        int $previousRevision,
        int $newRevision,
        string $newPayloadDigest,
        string $newSourceFactRef,
        ?string $originalIssuer = null,
        ?string $originalSourceNumber = null,
        ?string $importPolicyVersion = null,
    ): ImportMappingRecord;

    public function tombstoneMapping(
        ImportSourceIdentity $identity,
        int $sourceRecordRevision,
        string $reason,
    ): ImportMappingRecord;

    /**
     * @param  array<string, mixed>  $details
     */
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
    ): ImportReceiptRecord;

    public function findAppliedReceipt(ImportSourceIdentity $identity, int $sourceRecordRevision): ?ImportReceiptRecord;
}
