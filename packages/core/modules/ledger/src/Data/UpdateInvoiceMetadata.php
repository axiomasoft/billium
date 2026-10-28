<?php

declare(strict_types=1);

namespace Billium\Ledger\Data;

use Billium\Contracts\Attributes\Api;

/** Merges allow-list keys (`note`, `external_ref`, `labels`) into the metadata, in any invoice state (INV-13). */
#[Api]
final readonly class UpdateInvoiceMetadata
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $updateRef,
        public string $invoiceRef,
        public array $metadata,
    ) {}
}
