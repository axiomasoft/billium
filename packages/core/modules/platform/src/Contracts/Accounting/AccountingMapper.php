<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Accounting;

use Billium\Platform\Data\Accounting\AccountingFactEnvelope;
use Billium\Platform\Data\Accounting\AccountingMappingResult;
use Billium\Platform\Data\Accounting\AccountingSourceRecord;
use Billium\Platform\Data\Accounting\FinBridgeExportEnvelope;
use Packman\Attributes\Api;

/**
 * Platform-owned mapper from authenticated public economic source records to stable accounting facts and FinBridge export envelopes.
 *
 * Operates independently of the documents module and never writes GL entries or core financial tables.
 */
#[Api(since: '1.0')]
interface AccountingMapper
{
    public function map(AccountingSourceRecord $record): AccountingMappingResult;

    public function toFinBridge(
        AccountingFactEnvelope $fact,
        string $contractVersion = FinBridgeExportEnvelope::SCHEMA_V1,
    ): FinBridgeExportEnvelope;

    /** @return list<string> */
    public function supportedInputSchemas(): array;

    public function supportsInputSchema(string $schemaVersion): bool;

    /**
     * Returns an immutable mapper instance extended with additional upgraded input schema versions.
     *
     * @param  list<string>  $schemas
     */
    public function withAdditionalInputSchemas(array $schemas): self;
}
