<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\ReductionResult;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
interface OriginalIdentityRecovery
{
    /** Recover an inventory reference under the authenticated historical business identity and look it up on the original account. */
    public function recover(ExecutionScope $scope, string $sourceAuthority, string $account, string $reference): ReductionResult;
}
