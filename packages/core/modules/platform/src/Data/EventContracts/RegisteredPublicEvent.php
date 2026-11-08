<?php

declare(strict_types=1);

namespace Billium\Platform\Data\EventContracts;

use Billium\Contracts\Attributes\Api;
use Billium\Platform\Data\OutboxEvent;

/** D63: typed reference is validated against persisted registration inside the root Tx. */
#[Api]
final readonly class RegisteredPublicEvent
{
    /** @param list<string> $destinations */
    public function __construct(public EventContractRegistration $contract, public OutboxEvent $event, public array $destinations = ['default']) {}
}
