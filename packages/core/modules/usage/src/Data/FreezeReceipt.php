<?php

declare(strict_types=1);

namespace Billium\Usage\Data;

use Billium\Core\Data\Receipt;

final readonly class FreezeReceipt implements Receipt
{
    /** @param array<string, mixed> $payload */
    public function __construct(private array $payload, private string $class = 'usage.aggregate_frozen') {}

    public function receiptClass(): string
    {
        return $this->class;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->payload;
    }

    public static function snapshot(AggregateSnapshot $snapshot, bool $created): self
    {
        return new self([
            'snapshotRef' => $snapshot->snapshotRef, 'snapshotDigest' => $snapshot->snapshotDigest,
            'snapshotRevision' => $snapshot->snapshotRevision, 'quantity' => $snapshot->quantity,
            'quantityScale' => $snapshot->quantityScale, 'created' => $created,
        ]);
    }
}
