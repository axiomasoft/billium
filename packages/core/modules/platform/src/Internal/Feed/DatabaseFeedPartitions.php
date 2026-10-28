<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Feed;

use Billium\Core\Contracts\TransactionContext;
use Billium\Platform\Contracts\BootstrapItemHandler;
use Billium\Platform\Contracts\CustodyHandler;
use Billium\Platform\Contracts\FeedPartitions;
use Billium\Platform\Data\Inbox\BootstrapPartition;
use Billium\Platform\Data\Inbox\BootstrapStatus;
use Billium\Platform\Data\Inbox\CustodyResult;
use Billium\Platform\Internal\Inbox\CustodyProcessor;
use stdClass;

final readonly class DatabaseFeedPartitions implements FeedPartitions
{
    public function __construct(private CustodyProcessor $custody, private BootstrapCoordinator $bootstrap) {}

    public function process(TransactionContext $context, string $channel, stdClass $envelope, CustodyHandler $handler): CustodyResult
    {
        return $this->custody->process($context, $channel, $envelope, $handler);
    }

    public function drain(TransactionContext $context, int $partitionId, CustodyHandler $handler): CustodyResult
    {
        return $this->custody->drain($context, $partitionId, $handler);
    }

    public function retryDependency(TransactionContext $context, int $inboxId, CustodyHandler $handler): CustodyResult
    {
        return $this->custody->retryDependency($context, $inboxId, $handler);
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
