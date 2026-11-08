<?php

declare(strict_types=1);

namespace Billium\Ledger\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Core\Data\Receipt;

/** Immutable body returned by the owning DB routine; it is also the root runner's replay result (D31). */
#[Api]
final readonly class LedgerReceipt implements Receipt
{
    /** @param array<string, mixed> $result */
    public function __construct(private string $kind, private array $result, public bool $replayed = false) {}

    public function receiptClass(): string
    {
        return $this->kind;
    }

    public function body(): array
    {
        return $this->result;
    }
}
