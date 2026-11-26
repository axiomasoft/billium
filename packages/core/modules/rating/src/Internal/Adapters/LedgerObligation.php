<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Adapters;

use Packman\Attributes\Internal;

/** The part of a ledger obligation view that bounds how much a negative correction may credit. */
#[Internal]
final readonly class LedgerObligation
{
    private const array CREDITABLE_INVOICE_STATUSES = ['issued', 'partially_paid', 'paid', 'uncollectible'];

    public function __construct(
        public int $openMinor,
        public int $originalDueMinor,
        public int $adjustedMinor,
        public int $creditedMinor,
        public ?string $billedInvoiceRef,
        public ?string $billedInvoiceStatus,
    ) {}

    /**
     * Credit capacity: the open amount, or everything billed and not yet credited when paid amounts may be released.
     *
     * @return numeric-string
     */
    public function creditableMinor(bool $openOnly): string
    {
        return $openOnly
            ? (string) $this->openMinor
            : bcsub(bcadd((string) $this->originalDueMinor, (string) $this->adjustedMinor, 0), (string) $this->creditedMinor, 0);
    }

    public function creditableInvoiceRef(): ?string
    {
        return in_array($this->billedInvoiceStatus, self::CREDITABLE_INVOICE_STATUSES, true) ? $this->billedInvoiceRef : null;
    }
}
