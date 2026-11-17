<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Execution\Data\ExecutionScope;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
interface ReconciliationCommands
{
    /** Process one historical, authenticated provider page and retain its exact horizon. */
    public function inventory(ExecutionScope $scope, string $sourceAuthority, string $runRef, string $merchantRevisionRef, string $currency, DateTimeImmutable $from, DateTimeImmutable $through): StoredOutcome;

    /** Only look up the original attempt. Unsent attempts require explicit disposition. */
    public function targeted(ExecutionScope $scope, string $runRef, string $attemptRef, string $worker, DateTimeImmutable $leaseUntil): StoredOutcome;
}
