<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\ProviderEvidence;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
interface CallbackCustody
{
    public function receive(ExecutionScope $scope, string $merchantRevisionRef, ?string $operationHint, ProviderEvidence $delivery): StoredOutcome;
}
