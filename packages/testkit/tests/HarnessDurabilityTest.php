<?php

declare(strict_types=1);

namespace Billium\Testkit\Tests;

use Billium\Execution\Data\LookupRequest;
use Billium\Testkit\Drivers\DeterministicPaymentDriver;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DeterministicWriterHost;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\DurableReceiver;
use Billium\Testkit\Fixtures\FixtureClock;
use Billium\Testkit\Fixtures\ProcessBarrier;
use Billium\Testkit\Fixtures\ProcessIdentity;
use Billium\Testkit\Fixtures\ReceiverReceipt;
use DateTimeImmutable;
use Packman\Testkit\Data\Database\TestDatabaseIdentity;
use Packman\Testkit\Support\Database\TestDatabaseGuard;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Harness durability probes only; production recovery is owned by RESTORE drills. */
final class HarnessDurabilityTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/billium-durability-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory.'/*');
        if ($files === false) {
            throw new RuntimeException('Cannot enumerate durability fixtures');
        }
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function test_killed_child_leaves_provider_acceptance_durable_without_local_response(): void
    {
        $journal = new DurableJournal($this->directory.'/provider.log');
        $child = new ChildProcess([PHP_BINARY, __DIR__.'/harness-worker.php', $journal->path, 'crash-after-accept']);
        try {
            $pids = (new ProcessBarrier($journal))->await('accepted', 1, 10000);
            self::assertSame([$child->pid], $pids);
            self::assertNotSame(getmypid(), $child->pid);
        } finally {
            $child->kill();
        }
        self::assertNotSame(0, $child->wait());
        HarnessFactory::evidence('killed-child', ['parent_pid' => ProcessIdentity::current(), 'child_pid' => $child->pid, 'signal' => 9, 'provider_journal_sha256' => hash_file('sha256', $journal->path)]);
        $cap = SimulatedCapabilities::snapshot('account-a');
        $request = HarnessFactory::request($cap);
        $driver = new DeterministicPaymentDriver(new DurableJournal($journal->path), new FixtureClock(new DateTimeImmutable('2026-11-17T00:00:00Z')), $cap, static fn (): int => 0);
        self::assertSame(100, $driver->lookup(new LookupRequest($request->binding, 'operation-a', 'reference-a', null, $request->credentials))->snapshot?->capturedTotal->minor);
    }

    private function env(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new RuntimeException('Missing test environment '.$name);
        }

        return $value;
    }

    #[Group('pg')]
    public function test_provider_receiver_and_authority_journals_survive_real_pg_dump_restore(): void
    {
        $db = $this->env('DB_DATABASE');
        $host = $this->env('DB_HOST');
        $port = (int) $this->env('DB_PORT');
        TestDatabaseGuard::assertDestructiveSafe(
            new TestDatabaseIdentity('pgsql', $host, $port, $db, $this->env('DB_OWNER_USERNAME')),
        );
        $dsn = 'pgsql:host='.$host.';port='.$port.';dbname='.$db;
        $pdo = new PDO($dsn, $this->env('DB_OWNER_USERNAME'), $this->env('DB_OWNER_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $other = new PDO($dsn, $this->env('DB_OWNER_USERNAME'), $this->env('DB_OWNER_PASSWORD'));
        self::assertNotSame(HarnessFactory::integerQuery($pdo, 'SELECT pg_backend_pid()'), HarnessFactory::integerQuery($other, 'SELECT pg_backend_pid()'));
        $pdo->exec('CREATE TABLE billium_harness_restore_probe (id bigserial PRIMARY KEY, applied bigint NOT NULL)');
        try {
            $pdo->exec('INSERT INTO billium_harness_restore_probe (applied) VALUES (0)');
            $environment = array_replace(getenv(), ['PGHOST' => $this->env('DB_HOST'), 'PGPORT' => $this->env('DB_PORT'), 'PGUSER' => $this->env('DB_OWNER_USERNAME'), 'PGPASSWORD' => $this->env('DB_OWNER_PASSWORD')]);
            $backup = $this->directory.'/probe.dump';
            self::assertSame(0, (new ChildProcess(['pg_dump', '--format=custom', '--table=billium_harness_restore_probe', '--file='.$backup, '--dbname='.$db], $environment))->wait());
            self::assertGreaterThan(0, filesize($backup));
            $clock = new FixtureClock(new DateTimeImmutable('2026-11-17T00:00:00Z'));
            $cap = SimulatedCapabilities::snapshot('account-a');
            $request = HarnessFactory::request($cap);
            $provider = new DeterministicPaymentDriver(new DurableJournal($this->directory.'/provider.log'), $clock, $cap, static fn (): int => 0);
            $provider->execute($request);
            $receiverJournal = new DurableJournal($this->directory.'/receiver.log');
            $receipt = new ReceiverReceipt('event-a', 'root-a', 1, 'digest-a', 100, 0, ProcessIdentity::current());
            (new DurableReceiver($receiverJournal))->deliver($receipt);
            $hostJournal = new DurableJournal($this->directory.'/authority.log');
            $host = new DeterministicWriterHost($hostJournal, $clock, static fn (): int => 0);
            $claims = HarnessFactory::dispatch($request);
            $host->openSession($claims);
            $ticket = $host->ticket($claims);
            $host->retire($claims, 'simulated-restore-attachment-barrier');
            $hashes = [hash_file('sha256', $provider->journal->path), hash_file('sha256', $receiverJournal->path), hash_file('sha256', $hostJournal->path)];
            $pdo->exec('INSERT INTO billium_harness_restore_probe (applied) VALUES (100)');
            self::assertSame(2, HarnessFactory::integerQuery($pdo, 'SELECT count(*) FROM billium_harness_restore_probe'));
            self::assertSame(0, (new ChildProcess(['pg_restore', '--clean', '--if-exists', '--no-owner', '--no-privileges', '--dbname='.$db, $backup], $environment))->wait());
            self::assertSame(1, HarnessFactory::integerQuery($pdo, 'SELECT count(*) FROM billium_harness_restore_probe'));
            self::assertSame(0, HarnessFactory::integerQuery($pdo, 'SELECT sum(applied) FROM billium_harness_restore_probe'));
            self::assertSame(2, HarnessFactory::integerQuery($pdo, 'INSERT INTO billium_harness_restore_probe (applied) VALUES (0) RETURNING id'));
            $restoredHashes = [hash_file('sha256', $provider->journal->path), hash_file('sha256', $receiverJournal->path), hash_file('sha256', $hostJournal->path)];
            self::assertSame($hashes, $restoredHashes);
            self::assertSame(100, $provider->lookup(new LookupRequest($request->binding, 'operation-a', 'reference-a', null, $request->credentials))->snapshot?->capturedTotal->minor);
            self::assertEquals($receipt, (new DurableReceiver(new DurableJournal($receiverJournal->path)))->deliver($receipt));
            HarnessFactory::evidence('pg-dump-restore', ['parent_pid' => ProcessIdentity::current(), 'backend_ids' => [HarnessFactory::integerQuery($pdo, 'SELECT pg_backend_pid()'), HarnessFactory::integerQuery($other, 'SELECT pg_backend_pid()')], 'dump_sha256' => hash_file('sha256', $backup), 'external_journal_paths' => [$provider->journal->path, $receiverJournal->path, $hostJournal->path], 'before_restore_sha256' => $hashes, 'after_restore_sha256' => $restoredHashes, 'restored_rows' => 1, 'next_restored_sequence_value' => 2]);
            $this->expectException(RuntimeException::class);
            (new DeterministicWriterHost(new DurableJournal($hostJournal->path), $clock, static fn (): int => 0))->verify($ticket, $claims);
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS billium_harness_restore_probe');
        }
    }
}
