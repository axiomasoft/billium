<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Allocations;

use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Business receipt of an applied observation (BC-EVT-08, INV-08): the immutable AllocationReceipt row the guarded routine
 * wrote together with the applied projection, its allocations and outbox. A redelivery returns the same `receiptRef` and
 * body (`replayed`). `mode` is assign, hold, stale or refund_detail.
 */
#[Api(since: '1.0')]
final readonly class AllocationReceipt
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public string $receiptRef,
        public string $kind,
        public array $body,
        public bool $replayed = false,
    ) {
        if ($receiptRef === '' || $kind === '') {
            throw new InvalidArgumentException('An allocation receipt names its reference and kind');
        }
    }

    public function mode(): string
    {
        return is_string($this->body['mode'] ?? null) ? $this->body['mode'] : $this->kind;
    }
}
