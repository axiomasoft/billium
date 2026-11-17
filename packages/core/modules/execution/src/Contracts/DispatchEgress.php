<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Execution\Data\DispatchTicket;
use Billium\Execution\Data\DriverRequest;
use Billium\Execution\Data\ProviderLookupResult;
use Closure;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * invoke(DispatchTicket $ticket,DriverRequest $request,Closure $send): ProviderLookupResult; host checks enforceable egress fence at send boundary; never a permissive default. Fixture host journal simulates revocation independently.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface DispatchEgress
{
    /**
     * @param  Closure(): ProviderLookupResult  $send
     */
    public function invoke(DispatchTicket $ticket, DriverRequest $request, Closure $send): ProviderLookupResult;
}
