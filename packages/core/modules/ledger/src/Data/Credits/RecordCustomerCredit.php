<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Credits;

use Billium\Contracts\Data\Money;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Api;

/**
 * Monetary customer credit in one explicit account; no reward/quota or persistence behavior (D60).
 */
#[Api(since: '1.0')]
final readonly class RecordCustomerCredit
{
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $creditRef,
        public string $sourceRef,
        public Money $money,
    ) {}
}
