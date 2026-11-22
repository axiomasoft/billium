<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Import\Sources;

use Billium\Contracts\Contracts\Codec;
use Billium\Platform\Data\Import\ImportManifest;
use Billium\Platform\Data\Import\ImportRecordInput;
use Packman\Attributes\Internal;

/**
 * Product-agnostic export mapping profile translating one sanitized raw export record into a normalized external.import.v1 input.
 *
 * Profile version metadata is audit-only and must never alter sourceIdentityDigest or stable sourceFactRef.
 */
#[Internal]
interface SourceExportProfile
{
    public function profileId(): string;

    public function profileVersion(): string;

    public function supportedSchema(): string;

    /**
     * @param  array<string, mixed>  $rawRecord
     */
    public function normalizeRecord(array $rawRecord, ImportManifest $manifest, Codec $codec): ImportRecordInput;
}
