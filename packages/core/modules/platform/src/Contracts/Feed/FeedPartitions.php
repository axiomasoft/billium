<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Feed;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Platform\Contracts\Inbox\BootstrapItemHandler;
use Billium\Platform\Contracts\Inbox\CustodyHandler;
use Billium\Platform\Data\Inbox\BootstrapPartition;
use Billium\Platform\Data\Inbox\BootstrapStatus;
use Billium\Platform\Data\Inbox\CustodyResult;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use stdClass;

/**
 * Source-scoped observation custody and staged partition bootstrap (REQ-CON-27, REQ-FAIL-12/13/22/23). Mutating methods run
 * inside the already open root Tx of the caller's OperationRunner; one economic event per root Tx, so a caller drains
 * `CustodyResult::drainPartitionId` and `retryInboxId` with a new root operation each (fresh context and lock plan).
 *
 * The full source namespace is (channel, sourceAuthority, sourceLineage, sellerRef, environment, partitionRef); the
 * authenticated caller must be granted the sourceAuthority and match seller/environment/installation, else
 * billium.auth.denied before any custody. Raw custody (a RawCustody root) never invokes a handler and may commit while
 * the financial session is sealed; a financial root applies at most the one event whose sequence is next.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface FeedPartitions
{
    /**
     * Claims the immutable delivery identity, advances the custody frontier across contiguous arrivals, and applies the
     * delivery when it is next in order and the root is financial.
     *
     * @param  stdClass  $envelope  decoded and authenticated by the owning facade
     *
     * @throws ProblemException billium.auth.denied or billium.validation.invalid before any custody
     */
    public function process(TransactionContext $context, string $channel, stdClass $envelope, CustodyHandler $handler): CustodyResult;

    /** Read the authenticated source of a partition for the next financial root. */
    public function sourceForPartition(Caller $caller, string $channel, int $partitionId): string;

    /** Read the authenticated source of a retained delivery for the next financial root. */
    public function sourceForInbox(Caller $caller, string $channel, int $inboxId): string;

    /** Applies the single delivery at accounted_sequence + 1 of an active partition, if it is in custody. */
    public function drain(TransactionContext $context, int $partitionId, CustodyHandler $handler): CustodyResult;

    /** Retries the earliest parked dependency of one operation; a later revision never overtakes an earlier parked one. */
    public function retryDependency(TransactionContext $context, int $inboxId, CustodyHandler $handler): CustodyResult;

    /**
     * Next inbox-only parked revision (`park_kind = 'operation_revision'`) whose earlier inbox blocker has resolved, if any.
     *
     * @param  list<int>  $excludeInboxIds
     */
    public function nextUnblockedDependency(string $channel, Caller $caller, array $excludeInboxIds = []): ?int;

    /**
     * Verifies scope, digest and completeness of the whole snapshot and stages it durably; the live partition becomes
     * recovery_required and holds its tail. Repeating the same bootstrapRef and digest resumes the staged session.
     *
     * @return int session id
     *
     * @throws ProblemException billium.validation.invalid for an incomplete or altered snapshot, billium.auth.denied
     */
    public function stageBootstrap(TransactionContext $context, BootstrapPartition $snapshot): int;

    /** Applies the next undisposed item (one per root Tx). Returns the number of items still undisposed. */
    public function applyBootstrapItem(TransactionContext $context, int $sessionId, BootstrapItemHandler $handler): int;

    /**
     * One short Tx: only a session whose every item has a durable disposition adopts the cursor, retires the old partition
     * and activates the new one with the held tail. Returns the new partition id.
     *
     * @throws ProblemException billium.recovery.required when items are still undisposed
     */
    public function adoptBootstrap(TransactionContext $context, int $sessionId): int;

    public function bootstrapStatus(TransactionContext $context, int $sessionId): BootstrapStatus;
}
