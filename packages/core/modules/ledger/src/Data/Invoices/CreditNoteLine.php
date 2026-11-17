<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Invoices;

use Billium\Contracts\Data\Money;
use Packman\Attributes\Api;

/** One credited obligation of the invoice and the positive amount credited to it. */
#[Api(since: '1.0')]
final readonly class CreditNoteLine
{
    public function __construct(public string $obligationRef, public Money $money) {}
}
