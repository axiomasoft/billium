<?php

declare(strict_types=1);

namespace Billium\Core\Internal\Runner;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\Caller;
use Closure;
use LogicException;
use Packman\Attributes\Internal;

/** The only TransactionContext implementation; its state lives in the runner-owned Operation. */
#[Internal]
final readonly class RootContext implements TransactionContext
{
    public function __construct(
        private Operation $operation,
        private string $nonce,
        private Caller $caller,
        private OperationSpec $spec,
        private string $connectionName,
        private int $attempt,
    ) {}

    public function caller(): Caller
    {
        return $this->caller;
    }

    public function spec(): OperationSpec
    {
        return $this->spec;
    }

    public function connectionName(): string
    {
        return $this->connectionName;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function rootRef(): string
    {
        return substr(hash('sha256', $this->nonce), 0, 32);
    }

    public function sessionEpoch(): int
    {
        $this->assertActive();

        return $this->operation->sessionEpoch() ?? throw new LogicException('This root operation holds no financial session');
    }

    public function admittedModules(): array
    {
        return $this->operation->modules();
    }

    public function moduleGeneration(string $moduleId): int
    {
        $this->assertParticipant($moduleId);

        return $this->operation->modules()[$moduleId];
    }

    public function isActive(): bool
    {
        return $this->operation->isOpen();
    }

    public function assertActive(?string $connectionName = null): void
    {
        if (! $this->isActive()) {
            throw new LogicException('Stale TransactionContext: its root transaction is over');
        }
        if ($connectionName !== null && $connectionName !== $this->connectionName) {
            throw new LogicException("TransactionContext belongs to connection {$this->connectionName}, not {$connectionName}");
        }
    }

    public function assertParticipant(string $moduleId, ?string $connectionName = null): void
    {
        $this->assertActive($connectionName);
        if (! isset($this->operation->modules()[$moduleId])) {
            throw new LogicException("Module {$moduleId} is outside the admitted closure of {$this->spec->commandKind}");
        }
    }

    public function lock(LockRequest ...$requests): array
    {
        $this->assertActive();

        return $this->operation->locks()->acquire(...$requests);
    }

    /** @param Closure(): void $callback */
    public function afterCommit(Closure $callback): void
    {
        $this->assertActive();
        $this->operation->defer($callback);
    }
}
