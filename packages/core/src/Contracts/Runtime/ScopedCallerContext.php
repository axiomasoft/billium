<?php

declare(strict_types=1);

namespace Billium\Core\Contracts\Runtime;

use Billium\Core\Data\Runtime\Caller;
use Closure;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Host binds this same instance as CallerContext; run restores the prior caller even on failure. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ScopedCallerContext extends CallerContext
{
    /**
     * @template T
     *
     * @param  Closure(): T  $body
     * @return T
     */
    public function run(Caller $caller, Closure $body): mixed;
}
