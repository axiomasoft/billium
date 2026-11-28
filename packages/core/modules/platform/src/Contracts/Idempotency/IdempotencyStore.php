<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Idempotency;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\Caller;
use Billium\Platform\Data\Idempotency\IdempotencyClaim;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Atomic first-write claim of (environment, seller, command kind, key) with its semantic digest. Claim
 * and complete run inside the same root Tx of the runner; a concurrent claim of the same key waits on the unique index
 * (bounded by billium.idempotency.lock_timeout_ms → in_flight) and then sees the committed receipt, a conflict, or no
 * row because a none-policy error rolled the first claim back.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface IdempotencyStore
{
    /**
     * @throws ProblemException billium.idempotency.conflict (other digest) or
     *                          billium.idempotency.in_flight (lock timeout)
     */
    public function claim(Caller $caller, string $commandKind, string $commandKey, string $digest): IdempotencyClaim;

    public function complete(int $claimId, StoredOutcome $outcome): void;
}
