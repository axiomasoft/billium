<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Invoices;

use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Api;

/** issued|partially_paid → uncollectible; the reason and the operator authorization stay in the immutable receipt. */
#[Api(since: '1.0')]
final readonly class MarkUncollectible
{
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $invoiceRef,
        public string $reasonRef,
        public string $authorizationRef,
    ) {}
}
