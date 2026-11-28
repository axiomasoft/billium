<?php

declare(strict_types=1);

namespace Billium\Core\Data\Runner;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Business receipt of an accepted root operation. The runner stores `{class, body}` with the idempotency claim in the
 * same root Tx, so a replay of the same key and digest returns exactly this body.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface Receipt
{
    /** Stable receipt class name, e.g. `ledger.obligation_recorded`. */
    public function receiptClass(): string;

    /** @return array<string, mixed> JSON-safe body without secrets */
    public function body(): array;
}
