<?php

declare(strict_types=1);

namespace Billium\Ledger\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;

/**
 * Applies available value of exactly one funds source (a payment or a customer credit) to one obligation (INV-09).
 * `allocationRef` is the business identity: the same identity replays its AllocationReceipt under any command key.
 */
#[Api]
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
