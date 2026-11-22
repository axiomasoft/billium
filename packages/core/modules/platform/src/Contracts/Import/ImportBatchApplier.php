<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Import;

use Billium\Platform\Data\Import\ImportApplyOptions;
use Billium\Platform\Data\Import\ImportApplyResult;
use Billium\Platform\Data\Import\ImportCorrectionResult;
use Billium\Platform\Data\Import\ImportLinkedCorrection;
use Billium\Platform\Data\Import\ImportManifest;
use Billium\Platform\Data\Import\ImportRecordInput;
use Packman\Attributes\Api;

/**
 * Dependency-ordered, resumable import applier and post-apply linked correction service over existing monetary APIs.
 */
#[Api(since: '1.0')]
interface ImportBatchApplier
{
    /**
     * Stages (if needed), validates, approves and applies an external import batch according to the dependency DAG.
     *
     * Requires capabilityScope === 'import:apply' and expectedManifestDigest === $manifest->manifestDigest.
     *
     * @param  list<ImportRecordInput>  $records
     */
    public function apply(
        ImportManifest $manifest,
        array $records,
        ImportApplyOptions $options,
    ): ImportApplyResult;

    /**
     * Resumes an interrupted or partially applied batch from its last committed checkpoint without duplicating economic effects.
     */
    public function resume(
        string $batchId,
        ImportApplyOptions $options,
    ): ImportApplyResult;

    /**
     * Rejects a staged/validated/approved batch prior to applying any monetary state.
     */
    public function rejectBatch(string $batchId, string $reason): void;

    /**
     * Records a post-apply linked correction (positive obligation, credit note, draft adjustment or mapping tombstone)
     * without destructive rollback or automatic external PSP side effects.
     */
    public function applyLinkedCorrection(ImportLinkedCorrection $correction): ImportCorrectionResult;
}
