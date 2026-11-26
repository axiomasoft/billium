<?php

declare(strict_types=1);

namespace Billium\Core\Internal\Runner;

use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runtime\Caller;
use LogicException;
use Packman\Attributes\Internal;

/** Default binding: an operation without a host-authenticated caller fails closed. */
#[Internal]
final class UnboundCallerContext implements CallerContext
{
    public function current(): Caller
    {
        throw new LogicException('bind Billium\Core\Contracts\Runtime\CallerContext');
    }
}
