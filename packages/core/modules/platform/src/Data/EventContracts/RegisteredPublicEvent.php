<?php

declare(strict_types=1);

namespace Billium\Platform\Data\EventContracts;

use Billium\Platform\Data\Outbox\OutboxEvent;
use Packman\Attributes\Api;

/** D63: typed reference is validated against persisted registration inside the root Tx. */
#[Api(since: '1.0')]
final readonly class RegisteredPublicEvent
{
    /** @param list<string> $destinations */
    public function __construct(public EventContractRegistration $contract, public OutboxEvent $event, public array $destinations = ['default']) {}
}
