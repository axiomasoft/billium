<?php

declare(strict_types=1);

namespace Billium\Core\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;

/**
 * Business receipt of an accepted root operation. The runner stores `{class, body}` with the idempotency claim in the
 * same root Tx, so a replay of the same key and digest returns exactly this body (INV-05).
 */
#[Api]
#[Spi(SpiKind::Implement)]
interface Receipt
{
    /** Stable receipt class name, e.g. `ledger.obligation_recorded`. */
    public function receiptClass(): string;

    /** @return array<string, mixed> JSON-safe body without secrets */
    public function body(): array;
}
