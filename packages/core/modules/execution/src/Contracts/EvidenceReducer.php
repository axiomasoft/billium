<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\ProviderWorkClaim;
use Billium\Execution\Data\ReductionResult;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * reduce(string $workRef): ReductionResult is root entry, resolves complete plan outside root and applies inside runner. reduceIn(TransactionContext $ctx,ProviderWorkClaim $claim,MaterialSnapshot $snapshot): ReductionResult is same-root participant and cannot start nested root.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface EvidenceReducer
{
    public function reduce(string $workRef): ReductionResult;

    public function reduceIn(TransactionContext $ctx, ProviderWorkClaim $claim, MaterialSnapshot $snapshot): ReductionResult;
}
