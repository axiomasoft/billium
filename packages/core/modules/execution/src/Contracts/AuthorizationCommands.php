<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Contracts\Data\Envelopes\CapturePayment;
use Billium\Contracts\Data\Envelopes\VoidAuthorization;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Execution\Data\ExecutionScope;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
interface AuthorizationCommands
{
    public function admit(ExecutionScope $scope, CapturePayment|VoidAuthorization $command): StoredOutcome;
}
