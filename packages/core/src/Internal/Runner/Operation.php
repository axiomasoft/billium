<?php

declare(strict_types=1);

namespace Billium\Core\Internal\Runner;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\Caller;
use Closure;
use Illuminate\Database\Connection;
use LogicException;
use Packman\Attributes\Internal;

/**
 * One attempt of one root transaction: its LockSet, its admitted session/modules and the only TransactionContext minted
 * for it. Ending the attempt (commit, rollback or retry) invalidates the context for good.
 */
#[Internal]
final class Operation
{
    public readonly TransactionContext $context;

    private bool $open = true;

    private ?int $sessionEpoch = null;

    /** @var array<string, int> */
    private array $modules = [];

    /** @var list<Closure(): void> */
    private array $afterCommit = [];

    private readonly int $pdo;

    private readonly string $transactionId;

    private readonly LockSet $locks;

    public function __construct(private readonly Connection $connection, Caller $caller, OperationSpec $spec, int $attempt)
    {
        $this->pdo = spl_object_id($connection->getPdo());
        // PostgreSQL's top-level xid survives savepoints, but never a rollback/restart on the same PDO.
        $this->transactionId = $this->transactionId();
        $this->locks = new LockSet(static function (string $sql, array $bindings) use ($connection): array {
            $keys = [];
            foreach ($connection->select($sql, $bindings) as $row) {
                $key = is_object($row) ? ($row->lock_key ?? null) : null;
                $keys[] = is_int($key) ? $key : (is_string($key) ? $key : throw new LogicException('Lock key column is missing'));
            }

            return $keys;
        }, $caller);
        $this->context = new RootContext($this, bin2hex(random_bytes(16)), $caller, $spec, (string) $connection->getName(), $attempt);
    }

    public function isOpen(): bool
    {
        return $this->open
            && $this->connection->transactionLevel() > 0
            && spl_object_id($this->connection->getPdo()) === $this->pdo
            && $this->connection->getPdo()->inTransaction()
            && $this->transactionId() === $this->transactionId;
    }

    private function transactionId(): string
    {
        $row = $this->connection->selectOne('SELECT pg_current_xact_id()::text AS root_xid', [], false);

        return is_object($row) && is_string($row->root_xid ?? null)
            ? $row->root_xid : throw new LogicException('Cannot establish the root transaction identity');
    }

    public function sessionEpoch(): ?int
    {
        return $this->sessionEpoch;
    }

    /** @return array<string, int> */
    public function modules(): array
    {
        return $this->modules;
    }

    public function locks(): LockSet
    {
        return $this->locks;
    }

    /** @param Closure(): void $callback */
    public function defer(Closure $callback): void
    {
        $this->afterCommit[] = $callback;
    }

    public function bindSession(?int $epoch): void
    {
        $this->sessionEpoch = $epoch;
    }

    /** @param array<string, int> $modules */
    public function bindModules(array $modules): void
    {
        ksort($modules, SORT_STRING);
        $this->modules = $modules;
    }

    /** @return list<Closure(): void> callbacks to run after a successful commit */
    public function close(): array
    {
        $this->open = false;
        $callbacks = $this->afterCommit;
        $this->afterCommit = [];

        return $callbacks;
    }
}
