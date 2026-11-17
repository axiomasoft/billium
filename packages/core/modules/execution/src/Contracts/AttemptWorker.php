<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Execution\Data\ExecutionScope;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Dispatch or recover one durably admitted attempt. */
#[Api(since: '1.0')]
interface AttemptWorker
{
    public function dispatch(ExecutionScope $scope, string $attemptRef, string $worker, DateTimeImmutable $leaseUntil): StoredOutcome;

    public function recover(ExecutionScope $scope, string $attemptRef, string $worker, DateTimeImmutable $leaseUntil): StoredOutcome;
}
