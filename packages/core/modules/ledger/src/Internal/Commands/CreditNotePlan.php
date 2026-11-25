<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Packman\Attributes\Internal;

/**
 * The rows a credit note touches, read before and again after its lock plan is taken (COV-167): the invoice and its
 * currency, the credited obligations by ref, and the invoices, intents, payments and credits behind their allocations.
 */
#[Internal]
final readonly class CreditNotePlan
{
    /**
     * @param  array<string, int>  $obligations  obligation id by obligation ref, sorted by ref
     * @param  list<int>  $invoices  sorted, unique
     * @param  list<int>  $intents  sorted, unique
     * @param  list<int>  $payments  sorted, unique
     * @param  list<int>  $credits  sorted, unique
     */
    public function __construct(
        public int $invoice,
        public string $currency,
        public array $obligations,
        public array $invoices,
        public array $intents,
        public array $payments,
        public array $credits,
    ) {}

    public function lockPlan(): AllocateLockPlan
    {
        return new AllocateLockPlan($this->intents, array_values($this->obligations), [$this->invoice, ...$this->invoices], $this->payments, $this->credits);
    }

    public function sameAs(self $other): bool
    {
        return $this->invoice === $other->invoice && $this->currency === $other->currency && $this->obligations === $other->obligations
            && $this->invoices === $other->invoices && $this->intents === $other->intents && $this->payments === $other->payments
            && $this->credits === $other->credits;
    }
}
