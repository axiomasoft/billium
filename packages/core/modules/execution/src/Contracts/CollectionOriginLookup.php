<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\OriginDecision;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** The registered collection owner reserves original identities before execution admission (D63/F04). */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface CollectionOriginLookup
{
    public const string SOURCE_CONTEXT = 'collection';

    /** With a context, read the reservation on the current execution transaction's connection. */
    public function findOrigin(ExecutionScope $scope, string $operationRef, string $businessIntentRef, ?TransactionContext $context = null): ?OriginDecision;
}
