<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Adapters;

use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Platform\Contracts\Idempotency\IdempotencyReceiptQueries;
use Billium\Platform\Data\Idempotency\PinnedCommand;
use Packman\Attributes\Internal;

#[Internal]
final readonly class ReceiptReplayAdapter
{
    public function __construct(private IdempotencyReceiptQueries $receipts) {}

    public function replay(PinnedCommand $command): ?StoredOutcome
    {
        return $this->receipts->replayCommand($command->scope, $command->kind(), $command->key, $command->inputDigest);
    }
}
