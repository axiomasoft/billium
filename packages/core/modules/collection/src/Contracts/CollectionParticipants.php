<?php

declare(strict_types=1);

namespace Billium\Collection\Contracts;

use Billium\Execution\Contracts\Collection\CollectionOriginLookup;
use Billium\Execution\Contracts\CollectionCancellationParticipant;
use Billium\Execution\Contracts\CollectionExecutionGuard;
use Billium\Execution\Data\ExecutionScope;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** The collection owner participants used by host composition (D63/F04). */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface CollectionParticipants extends CollectionCancellationParticipant, CollectionExecutionGuard, CollectionOriginLookup
{
    /** Closes a revoked step with current evidence of no admission or with the execution owner's cancellation CAS. */
    public function closeRevoked(ExecutionScope $scope, string $authorizationRef): string;
}
