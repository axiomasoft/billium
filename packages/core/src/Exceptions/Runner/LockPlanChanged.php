<?php

declare(strict_types=1);

namespace Billium\Core\Exceptions\Runner;

use Billium\Core\Errors\BilliumCoreErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Exceptions\CodedException;

/**
 * The row set re-read under locks differs from the pre-read plan. The runner rolls back and retries the whole root
 * database work within its budget; the previous TransactionContext is invalid (invariant-model).
 */
#[Api(since: '1.0')]
final class LockPlanChanged extends CodedException
{
    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        $code = BilliumCoreErrorCode::LockPlanChanged;

        return new ErrorDescriptor(
            code: $code,
            category: $code->category(),
            retryable: $code->isRetryable(),
        );
    }
}
