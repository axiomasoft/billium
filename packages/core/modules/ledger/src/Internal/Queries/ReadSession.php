<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Queries;

use Billium\Core\Contracts\CallerContext;
use Billium\Core\Data\Caller;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;

/** REQ-CON-14: an independent consistent read, with PostgreSQL enforcing the no-write boundary. */
final readonly class ReadSession
{
    public function __construct(private DatabaseManager $databases, private CallerContext $callers) {}

    /**
     * @template T
     *
     * @param  Closure(Connection, Caller): T  $read
     * @return T
     */
    public function run(Closure $read): mixed
    {
        $caller = $this->callers->current();
        $name = config('billium.connection') ?? config('database.default');
        $db = $this->databases->connection(is_string($name) ? $name : null);
        if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new LogicException('Ledger queries require an independent read-only root transaction');
        }

        return $db->transaction(static function () use ($db, $caller, $read): mixed {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return $read($db, $caller);
        });
    }
}
