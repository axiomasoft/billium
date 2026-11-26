<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Contracts\Data\Envelopes\RequestRefund;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Execution\Data\ExecutionScope;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
interface RefundCommands
{
    public function admit(ExecutionScope $scope, RequestRefund $command): StoredOutcome;
}
