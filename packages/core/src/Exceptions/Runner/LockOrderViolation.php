<?php

declare(strict_types=1);

namespace Billium\Core\Exceptions\Runner;

use Billium\Core\Errors\BilliumCoreErrorCode;
use LogicException;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\DescribesError;
use Packman\Errors\Data\ErrorDescriptor;

/** A lock request below an already acquired (rank, segment, key) or an upgrade of a held shared lock (D27). */
#[Api(since: '1.0')]
final class LockOrderViolation extends LogicException implements DescribesError
{
    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        $code = BilliumCoreErrorCode::LockOrderViolation;

        return new ErrorDescriptor(
            code: $code,
            category: $code->category(),
            retryable: $code->isRetryable(),
        );
    }
}
