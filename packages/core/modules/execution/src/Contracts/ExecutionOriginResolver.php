<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\OriginDecision;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Host resolves one original commercial basis before entering the financial transaction. Missing or ambiguous evidence must refuse. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ExecutionOriginResolver
{
    public function resolve(ExecutionScope $scope, string $operationRef, string $businessIntentRef): OriginDecision;
}
