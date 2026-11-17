<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Queries;

use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runtime\Caller;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;

/** REQ-CON-14: an independent consistent read, with PostgreSQL enforcing the no-write boundary. */
#[Internal]
final readonly class ReadSession
{
    public function __construct(
        private DatabaseManager $databases,
        private CallerContext $callers,
        private BilliumOptions $options,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(Connection, Caller): T  $read
     * @return T
     */
    public function run(Closure $read): mixed
    {
        $caller = $this->callers->current();
        $db = $this->databases->connection($this->options->connection);
        if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new LogicException('Ledger queries require an independent read-only root transaction');
        }

        return $db->transaction(static function () use ($db, $caller, $read): mixed {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return $read($db, $caller);
        });
    }
}
