<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Allocations;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Api;

/**
 * Applies available value of exactly one funds source (a payment or a customer credit) to one obligation (INV-09).
 * `allocationRef` is the business identity: the same identity replays its AllocationReceipt under any command key.
 */
#[Api(since: '1.0')]
final readonly class Allocate
{
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $allocationRef,
        public string $obligationRef,
        public Money $money,
        public ?string $paymentRef = null,
        public ?string $customerCreditRef = null,
        public string $cause = 'manual',
    ) {
        if (($paymentRef === null) === ($customerCreditRef === null)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'exactly one funds source: a payment or a customer credit');
        }
    }
}
