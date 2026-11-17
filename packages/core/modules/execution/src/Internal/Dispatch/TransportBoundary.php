<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Dispatch;

use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class TransportBoundary
{
    public function __construct(private DatabaseManager $databases) {}

    public function assertOutsideTransaction(): void
    {
        foreach ($this->databases->getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
                throw new LogicException('Host and driver operations require transaction level zero');
            }
        }
    }
}
