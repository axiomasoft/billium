<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Publication;

use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Contracts\PaymentObservationSink;
use Billium\Platform\Contracts\Outbox\FactualCommandOutbox;
use Billium\Platform\Data\EventContracts\PreparedFactualCommand;
use Packman\Attributes\Internal;

#[Internal]
final readonly class PlatformPaymentObservationSink implements PaymentObservationSink
{
    public function __construct(private FactualCommandOutbox $commands) {}

    public function record(TransactionContext $ctx, string $commandRef, string $businessRef, PaymentObservation $observation): int
    {
        return $this->commands->record($ctx, new PreparedFactualCommand($commandRef, $businessRef, $observation));
    }
}
