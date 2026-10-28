<?php

declare(strict_types=1);

namespace Billium\Ledger\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Data\Money;

/** One credited obligation of the invoice and the positive amount credited to it. */
#[Api]
final readonly class CreditNoteLine
{
    public function __construct(public string $obligationRef, public Money $money) {}
}
