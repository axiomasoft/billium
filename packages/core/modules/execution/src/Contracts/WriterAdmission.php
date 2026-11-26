<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Execution\Data\DispatchRequest;
use Billium\Execution\Data\DispatchTicket;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * ticket(DispatchRequest $request): DispatchTicket; verify(DispatchTicket $ticket,DispatchRequest $request): void; both Tx0. Authority stopped/timeout/expired/foreign ticket refuses new effects.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface WriterAdmission
{
    public function ticket(DispatchRequest $request): DispatchTicket;

    public function verify(DispatchTicket $ticket, DispatchRequest $request): void;
}
