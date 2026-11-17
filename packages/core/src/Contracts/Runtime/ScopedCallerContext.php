<?php

declare(strict_types=1);

namespace Billium\Core\Contracts\Runtime;

use Billium\Core\Data\Runtime\Caller;
use Closure;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Bind the same service as CallerContext. During run(), current() returns the exact supplied Caller object.
 * Verified observation origins bind to that object's callback lifetime. Restore the previous caller in finally,
 * including nested calls and exceptions.
 */
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
