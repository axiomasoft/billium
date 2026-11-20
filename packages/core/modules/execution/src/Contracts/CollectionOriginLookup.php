<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\OriginDecision;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** The registered collection owner reserves original identities before execution admission (D63/F04). */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface CollectionOriginLookup
{
    public const string SOURCE_CONTEXT = 'collection';

    public function findOrigin(ExecutionScope $scope, string $operationRef, string $businessIntentRef): ?OriginDecision;
}
