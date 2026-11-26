<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Feed;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Platform\Contracts\Feed\FeedPartitions;
use Billium\Platform\Contracts\Inbox\BootstrapItemHandler;
use Billium\Platform\Contracts\Inbox\CustodyHandler;
use Billium\Platform\Data\Inbox\BootstrapPartition;
use Billium\Platform\Data\Inbox\BootstrapStatus;
use Billium\Platform\Data\Inbox\CustodyResult;
use Billium\Platform\Data\Inbox\VerifiedObservationBootstrap;
use Billium\Platform\Internal\Inbox\CustodyProcessor;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class DatabaseFeedPartitions implements FeedPartitions
{
    public function __construct(private CustodyProcessor $custody, private BootstrapCoordinator $bootstrap) {}

    public function process(TransactionContext $context, string $channel, stdClass $envelope, CustodyHandler $handler): CustodyResult
    {
        return $this->custody->process($context, $channel, $envelope, $handler);
    }

    public function processQualified(TransactionContext $context, string $channel, stdClass $envelope, CustodyHandler $handler, string $rootOperationRef, string $proofDigest): CustodyResult
    {
        return $this->custody->process($context, $channel, $envelope, $handler, $rootOperationRef, $proofDigest);
    }

    public function scopeForPartition(Caller $caller, string $channel, int $partitionId): FinancialScope
    {
        return $this->custody->scopeForPartition($caller, $channel, $partitionId);
    }

    public function scopeForInbox(Caller $caller, string $channel, int $inboxId): FinancialScope
    {
        return $this->custody->scopeForInbox($caller, $channel, $inboxId);
    }

    public function sourceForPartition(Caller $caller, string $channel, int $partitionId): string
    {
        return $this->custody->sourceForPartition($caller, $channel, $partitionId);
    }

    public function sourceForInbox(Caller $caller, string $channel, int $inboxId): string
    {
        return $this->custody->sourceForInbox($caller, $channel, $inboxId);
    }

    public function drain(TransactionContext $context, int $partitionId, CustodyHandler $handler): CustodyResult
    {
        return $this->custody->drain($context, $partitionId, $handler);
    }

    public function retryDependency(TransactionContext $context, int $inboxId, CustodyHandler $handler): CustodyResult
    {
        return $this->custody->retryDependency($context, $inboxId, $handler);
    }

    /** @param  list<int>  $excludeInboxIds */
    public function nextUnblockedDependency(string $channel, Caller $caller, array $excludeInboxIds = []): ?int
    {
        return $this->custody->nextUnblockedDependency($channel, $caller, $excludeInboxIds);
    }

    public function stageQualifiedBootstrap(TransactionContext $context, BootstrapPartition $snapshot, VerifiedObservationBootstrap $grant): int
    {
        return $this->bootstrap->stage($context, $snapshot, $grant);
    }

    public function stageBootstrap(TransactionContext $context, BootstrapPartition $snapshot): int
    {
        return $this->bootstrap->stage($context, $snapshot);
    }

    public function applyBootstrapItem(TransactionContext $context, int $sessionId, BootstrapItemHandler $handler): int
    {
        return $this->bootstrap->applyNext($context, $sessionId, $handler);
    }

    public function adoptBootstrap(TransactionContext $context, int $sessionId): int
    {
        return $this->bootstrap->adopt($context, $sessionId);
    }

    public function bootstrapStatus(TransactionContext $context, int $sessionId): BootstrapStatus
    {
        return $this->bootstrap->status($context, $sessionId);
    }
}
