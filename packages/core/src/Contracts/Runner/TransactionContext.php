<?php

declare(strict_types=1);

namespace Billium\Core\Contracts\Runner;

use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\Caller;
use Closure;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Capability of exactly one root transaction attempt (transaction-participants). Only the current OperationRunner
 * mints it, for its connection and root nonce, after the idempotency claim; it carries the authenticated caller, the
 * adopted financial-session epoch, the admitted module closure with generations and the root LockSet. It expires on
 * commit, rollback and retry. A typed participant never trusts an object merely implementing this interface: it calls
 * OperationRunner::assertCurrent(), which accepts only the live context the runner minted. It never exposes SQL or a
 * connection object. Reflection/DBA compromise is not a supported adversary.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface TransactionContext
{
    public function caller(): Caller;

    public function spec(): OperationSpec;

    /** Name of the root connection; participants write only through it. */
    public function connectionName(): string;

    /** 1 for the first attempt; a retry mints a new context with the next number. */
    public function attempt(): int;

    /** Opaque identity of this root attempt; never stored as authority. */
    public function rootRef(): string;

    /** Adopted financial-session epoch held FOR SHARE to commit; only financial operations have one. */
    public function sessionEpoch(): int;

    /** @return array<string, int> admitted module closure with the generations validated under rank 25 */
    public function admittedModules(): array;

    public function moduleGeneration(string $moduleId): int;

    public function isActive(): bool;

    /** @throws \LogicException stale context or another connection */
    public function assertActive(?string $connectionName = null): void;

    /**
     * A participant of `$moduleId` may act only inside the admitted closure.
     *
     * @throws \LogicException
     */
    public function assertParticipant(string $moduleId, ?string $connectionName = null): void;

    /**
     * Locks planned row sets in the global order (LockOrderViolation otherwise).
     *
     * @return list<int|string> locked keys
     */
    public function lock(LockRequest ...$requests): array;

    /**
     * Wakes durable work after commit; never a substitute for the outbox row written in this Tx.
     *
     * @param  Closure(): void  $callback
     */
    public function afterCommit(Closure $callback): void;
}
