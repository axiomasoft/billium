<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Participants;

use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Contracts\TransactionContext;
use Billium\Usage\Contracts\AggregationLocks;
use Billium\Usage\Internal\Errors\UsageProblems;
use Billium\Usage\Internal\Storage\AggregateStore;

final readonly class DatabaseAggregationLocks implements AggregationLocks
{
    public function __construct(private OperationRunner $runner, private AggregateStore $store) {}

    public function acquire(TransactionContext $context, array $snapshots): void
    {
        $this->runner->assertCurrent(context: $context, moduleId: 'usage');
        $db = $this->store->database($context->connectionName());
        $requests = [];
        foreach ($snapshots as $ref) {
            $snapshot = $this->store->snapshot(db: $db, ref: $ref->snapshotRef) ?? throw UsageProblems::error('snapshot_superseded');
            $requests[] = AggregateStore::lockRequest($this->store->window($snapshot));
        }
        if ($requests !== []) {
            $context->lock(...$requests);
        }
    }
}
