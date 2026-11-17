<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Outbox;

use Billium\Core\Contracts\Runtime\EventPublisher;
use Billium\Platform\Tests\PlatformTestCase;
use LogicException;

final class RecordingPublisher implements EventPublisher
{
    /** @var list<array<string,mixed>> */
    public array $messages = [];

    public function publish(string $destinationRef, array $message): void
    {
        $db = PlatformTestCase::connection('pgsql');
        if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new LogicException('Publisher called inside transaction');
        }
        $this->messages[] = $message;
    }
}
