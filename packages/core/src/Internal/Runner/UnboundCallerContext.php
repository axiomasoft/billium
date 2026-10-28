<?php

declare(strict_types=1);

namespace Billium\Core\Internal\Runner;

use Billium\Core\Contracts\CallerContext;
use Billium\Core\Data\Caller;
use LogicException;

/** Default binding: an operation without a host-authenticated caller fails closed. */
final class UnboundCallerContext implements CallerContext
{
    public function current(): Caller
    {
        throw new LogicException('bind Billium\Core\Contracts\CallerContext');
    }
}
