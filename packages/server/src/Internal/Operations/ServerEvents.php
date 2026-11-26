<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Operations;

use Illuminate\Support\Facades\Log;
use Packman\Attributes\Internal;
use Throwable;

#[Internal]
enum ServerEvents: string
{
    case ReceiptReplayed = 'billium.server.receipt_replayed';
    case CommandConflict = 'billium.server.command_conflict';
    case ObservationConflict = 'billium.server.observation_conflict';
    case DatabaseUnavailable = 'billium.server.database_unavailable';

    public function record(): void
    {
        try {
            Log::info($this->value);
        } catch (Throwable) {
            // The host log sink cannot change an already decided financial response.
        }
    }
}
