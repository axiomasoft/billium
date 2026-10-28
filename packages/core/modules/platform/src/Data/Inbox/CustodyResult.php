<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Inbox;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Enums\ProblemCode;

/**
 * Result of one custody step, returned after the root Tx commits (D31): the caller maps `problem()` to its transport
 * answer. A transport ACK of any disposition confirms custody only, never ProjectionApplied (INV-08).
 * `drainPartitionId` names a partition whose next accounted sequence is already in custody: the caller drains it in a
 * NEW root Tx, one sequence at a time.
 */
#[Api]
final readonly class CustodyResult
{
    public function __construct(
        public CustodyDisposition $disposition,
        public ?int $inboxId = null,
        public ?string $status = null,
        public ?string $resultRef = null,
        public ?int $drainPartitionId = null,
        public ?int $retryInboxId = null,
    ) {}

    public function problem(): ?ProblemCode
    {
        return $this->disposition->problem();
    }

    public function applied(): bool
    {
        return $this->status === 'applied';
    }

    public function withDrain(?int $partitionId, ?int $retryInboxId = null): self
    {
        return new self($this->disposition, $this->inboxId, $this->status, $this->resultRef, $partitionId, $retryInboxId ?? $this->retryInboxId);
    }
}
