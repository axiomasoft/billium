<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Idempotency;

use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Platform\Data\Idempotency\IdempotencyClaim;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Persists the authenticated selection, optional input fingerprint and original execution reference with the first claim. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface FinancialIdempotencyStore extends IdempotencyStore
{
    public function claimScoped(Caller $caller, FinancialScope $scope, string $commandKind, string $commandKey, string $digest, ?string $inputDigest = null, ?string $originalExecutionBindingRef = null): IdempotencyClaim;
}
