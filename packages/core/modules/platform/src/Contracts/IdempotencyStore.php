<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Caller;
use Billium\Core\Data\StoredOutcome;
use Billium\Platform\Data\IdempotencyClaim;

/**
 * Atomic first-write claim of (environment, seller, command kind, key) with its semantic digest (INV-05, D51). Claim
 * and complete run inside the same root Tx of the runner; a concurrent claim of the same key waits on the unique index
 * (bounded by billium.idempotency.lock_timeout_ms → in_flight) and then sees the committed receipt, a conflict, or no
 * row because a none-policy error rolled the first claim back (D31).
 */
#[Api]
#[Spi(SpiKind::Call)]
interface IdempotencyStore
{
    /**
     * @throws ProblemException billium.idempotency.conflict (other digest) or
     *                          billium.idempotency.in_flight (lock timeout)
     */
    public function claim(Caller $caller, string $commandKind, string $commandKey, string $digest): IdempotencyClaim;

    public function complete(int $claimId, StoredOutcome $outcome): void;
}
