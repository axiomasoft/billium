<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal\Locks;

use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Schedule\Contracts\OccurrenceLocks;
use LogicException;
use Packman\Attributes\Internal;
use WeakMap;

#[Internal]
final class ScheduleLockRequests implements OccurrenceLocks
{
    /** @var WeakMap<TransactionContext, array<string, true>> */
    private WeakMap $held;

    public function __construct(private readonly OperationRunner $runner)
    {
        $this->held = new WeakMap;
    }

    public static function schedule(string $ref): LockRequest
    {
        return new LockRequest(rank: LockRank::BillingSchedule, table: 'billium_schedule_billing_schedule', keys: [$ref], keyColumn: 'schedule_ref');
    }

    public function acquire(TransactionContext $context, array $occurrenceRefs): void
    {
        $this->runner->assertCurrent(context: $context, moduleId: 'schedule');
        if ($occurrenceRefs === []) {
            return;
        }
        $found = $context->lock(new LockRequest(rank: LockRank::BillingOccurrence,
            table: 'billium_schedule_billing_occurrence', keys: $occurrenceRefs, keyColumn: 'occurrence_ref'));
        $held = $this->held[$context] ?? [];
        foreach ($found as $ref) {
            $held[(string) $ref] = true;
        }
        $this->held[$context] = $held;
    }

    public function assertHeld(TransactionContext $context, string $ref): void
    {
        if (! isset(($this->held[$context] ?? [])[$ref])) {
            throw new LogicException('Occurrence parent lock must be preplanned through OccurrenceLocks before the participant');
        }
    }
}
