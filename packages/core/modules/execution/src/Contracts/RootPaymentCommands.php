<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Contracts\Data\Envelopes\AuthorizePayment;
use Billium\Contracts\Data\Envelopes\CancelOperation;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Execution\Data\ExecutionScope;
use Packman\Attributes\Api;

/** Authenticated root payment commands. */
#[Api(since: '1.0')]
interface RootPaymentCommands
{
    public function admit(ExecutionScope $scope, ExecutePayment|AuthorizePayment $command): StoredOutcome;

    public function cancel(ExecutionScope $scope, CancelOperation $command): StoredOutcome;
}
