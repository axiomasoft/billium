<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Invoices;

use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Api;

/** Merges allow-list keys (`note`, `external_ref`, `labels`) into the metadata, in any invoice state. */
#[Api(since: '1.0')]
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
