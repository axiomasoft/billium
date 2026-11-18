<?php

declare(strict_types=1);

namespace Billium\Testkit\Tests;

use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\DurableReceiver;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessBarrier;
use Billium\Testkit\Fixtures\ProcessIdentity;
use Billium\Testkit\Fixtures\ReceiverReceipt;
use Billium\Testkit\Oracles\IndependentFinancialOracle;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExternalJournalTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/billium-journals-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory.'/*');
        if ($files === false) {
            throw new RuntimeException('Cannot enumerate fixture files');
        }
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function test_receiver_lost_receipt_replay_is_deduplicated_after_restart(): void
    {
        $journal = new DurableJournal($this->directory.'/receiver.log');
        $receipt = new ReceiverReceipt('event-a', 'root-a', 1, 'digest-a', 100, 20, ProcessIdentity::current());
        (new DurableReceiver($journal))->deliver($receipt);
        $restored = new DurableReceiver(new DurableJournal($journal->path));
        self::assertEquals($receipt, $restored->deliver($receipt));
        self::assertCount(1, array_filter($journal->records(), static fn (object $r): bool => $r instanceof ReceiverReceipt));
        self::assertCount(2, array_filter($journal->records(), static fn (object $r): bool => $r instanceof JournalEvent));
        $this->expectException(RuntimeException::class);
        $restored->deliver(new ReceiverReceipt('event-b', 'root-a', 1, 'conflict', 200, 20, ProcessIdentity::current()));
    }

    public function test_oracle_keeps_observed_truth_separate_from_applied_and_counts_reservations(): void
    {
        $oracle = new IndependentFinancialOracle;
        $oracle->observe('root', 'auth', ['authorized' => 1000, 'captured' => 0, 'refunded' => 0, 'voided' => 0]);
        $oracle->observe('root', 'capture', ['authorized' => 0, 'captured' => 600, 'refunded' => 0, 'voided' => 0]);
        $oracle->observe('root', 'refund', ['authorized' => 0, 'captured' => 0, 'refunded' => 200, 'voided' => 0]);
        $oracle->observe('root', 'refund', ['authorized' => 0, 'captured' => 0, 'refunded' => 200, 'voided' => 0]);
        self::assertSame(['capture' => 250, 'refund' => 100, 'void' => 250], $oracle->capacities('root', 100, 300, 50));
        $oracle->assertApplied('root', ['authorized' => 1000, 'captured' => 600, 'refunded' => 200, 'voided' => 0]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Observed and applied totals diverge');
        $oracle->assertApplied('root', ['authorized' => 1000, 'captured' => 600, 'refunded' => 0, 'voided' => 0]);
    }

    public function test_oracle_rejects_over_capacity_and_conflicting_fact_identity(): void
    {
        $oracle = new IndependentFinancialOracle;
        $oracle->observe('root', 'auth', ['authorized' => 100, 'captured' => 0, 'refunded' => 0, 'voided' => 0]);
        try {
            $oracle->capacities('root', 60, 0, 41);
            self::fail('Overcapacity accepted');
        } catch (RuntimeException) {
            self::assertSame(100, $oracle->totals('root')['authorized']);
        }
        $this->expectException(RuntimeException::class);
        $oracle->observe('root', 'auth', ['authorized' => 101, 'captured' => 0, 'refunded' => 0, 'voided' => 0]);
    }

    #[Group('pg')]
    public function test_twenty_real_processes_append_without_lost_updates_and_keep_distinct_ids(): void
    {
        $journal = new DurableJournal($this->directory.'/process.log');
        $barrier = new ProcessBarrier($journal);
        $children = [];
        for ($i = 0; $i < 20; $i++) {
            $children[] = new ChildProcess([PHP_BINARY, __DIR__.'/harness-worker.php', $journal->path, 'append']);
        }
        self::assertCount(20, $barrier->await('ready', 20, 15000));
        $barrier->arrive('release');
        foreach ($children as $child) {
            self::assertSame(0, $child->wait());
        }
        $done = array_filter($journal->records(), static fn (object $r): bool => $r instanceof JournalEvent && $r->kind === 'child.done');
        self::assertCount(20, $done);
        $backends = [];
        foreach ($journal->records() as $record) {
            if ($record instanceof JournalEvent && $record->kind === 'child.backend') {
                $backends[] = $record->data['backend'];
            }
        }
        self::assertCount(20, array_unique($backends));
        HarnessFactory::evidence('twenty-processes', ['parent_pid' => ProcessIdentity::current(), 'child_pids' => array_map(static fn (ChildProcess $child): int => $child->pid, $children), 'backend_ids' => $backends, 'journal_sha256' => hash_file('sha256', $journal->path)]);
        self::assertCount(20, array_unique(array_map(static fn (JournalEvent $r): int => $r->pid, $done)));
    }

    public function test_corrupt_durable_journal_is_rejected(): void
    {
        $journal = new DurableJournal($this->directory.'/corrupt.log');
        file_put_contents($journal->path, "partial-write\n");
        $this->expectException(RuntimeException::class);
        $journal->records();
    }
}
