<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Concurrency;

use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\LockRequest;
use Billium\Core\Enums\FinancialMode;
use Billium\Core\Enums\LockRank;
use Billium\Core\Exceptions\LockOrderViolation;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Platform\Tests\Inbox\CustodyScenario;
use Billium\Platform\Tests\Inbox\InboxTestCase;
use Billium\Platform\Tests\Inbox\JournalHandler;
use PHPUnit\Framework\Attributes\Group;

/** Rank 30 (partition) precedes every domain lock: no handler discovers a second partition after it locked its rows (D27/D60). */
#[Group('INV-03')]
#[Group('ACC-CON-16')]
final class InboxDrainLockOrderTest extends InboxTestCase
{
    public function test_a_handler_that_drains_another_partition_after_locking_its_rows_violates_the_lock_order(): void
    {
        $first = $this->seedPartition(0, partition: 'part-1');
        $second = $this->seedPartition(0, partition: 'part-2');
        $effect = $this->effectRow();
        $feed = $this->feed();
        $handler = new JournalHandler([], 0, function (TransactionContext $context, $row, JournalHandler $self) use ($effect, $feed, $second): void {
            $context->lock(new LockRequest(LockRank::Obligation, 'billium_test_effect', [$effect]));
            // The forbidden inline loop: a second event of ANOTHER partition would need rank 30 after rank 70.
            $feed->drain($context, $second, $self);
        });
        $this->custody($this->envelope(1, event: 'evt-p2-1', partition: 'part-2'), new JournalHandler, FinancialMode::RawCustody);

        try {
            $this->custody($this->envelope(1, event: 'evt-p1-1', partition: 'part-1'), $handler);
            self::fail('The inline second drain must be refused');
        } catch (LockOrderViolation) {
            // expected: the whole root rolled back
        }

        self::assertSame(0, $this->rows('billium_test_journal'));
        self::assertSame(0, $this->partitionRow($first)['accounted_sequence']);
        self::assertSame(0, $this->partitionRow($second)['accounted_sequence']);
        self::assertSame(0, $this->rows('billium_inbox_message', "external_event_id = 'evt-p1-1'"), 'custody rolled back with the failed root');
        self::assertSame('ready', $this->inbox('evt-p2-1')['status']);
    }

    public function test_workers_over_two_partitions_sharing_a_locked_row_finish_without_a_deadlock(): void
    {
        $this->seedPartition(0, partition: 'part-1');
        $this->seedPartition(0, partition: 'part-2');
        $effect = $this->effectRow();
        $before = $this->deadlocks();

        $results = ConcurrencyStand::run(CustodyScenario::class, 4, [
            'sequences' => [1, 2, 1, 2], 'partitions' => ['part-1', 'part-1', 'part-2', 'part-2'], 'lock_effect' => $effect, 'delays' => [0, 0, 0, 0],
        ], 90);

        foreach ($results as $result) {
            self::ok($result);
        }
        self::assertSame(2, $this->rows('billium_feed_partition', 'accounted_sequence = 2'));
        self::assertSame(4, $this->rows('billium_inbox_message', "status = 'applied'"));
        foreach (['part-1', 'part-2'] as $partition) {
            $journal = array_values(array_filter($this->journal(), static fn (string $ref): bool => str_contains($ref, $partition)));
            self::assertSame(["evt-{$partition}-1", "evt-{$partition}-2"], $journal);
        }
        self::assertSame($before, $this->deadlocks(), 'one lock order, no deadlock detected by PostgreSQL');
    }

    private function effectRow(): int
    {
        return self::integer($this->value("INSERT INTO billium_test_effect (seller_ref, environment, ref) VALUES ('seller-a', 'test', 'shared') RETURNING id"));
    }

    private function deadlocks(): int
    {
        return self::integer($this->value('SELECT deadlocks FROM pg_stat_database WHERE datname = current_database()'));
    }
}
