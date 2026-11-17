<?php

declare(strict_types=1);
require dirname(__DIR__, 3).'/vendor/autoload.php';
use Billium\Testkit\Drivers\DeterministicPaymentDriver;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessBarrier;
use Billium\Testkit\Fixtures\ProcessIdentity;
use Billium\Testkit\Tests\HarnessFactory;

$path = $argv[1];
$mode = $argv[2];
$journal = new DurableJournal($path);
$barrier = new ProcessBarrier($journal);
if ($mode === 'append' || $mode === 'refund') {
    $database = getenv('DB_DATABASE');
    if (! is_string($database) || ! str_ends_with($database, '_test') || getenv('DB_HOST') !== 'postgres') {
        throw new RuntimeException('Child test database guard refused');
    }
    $pdo = new PDO('pgsql:host=postgres;port=5432;dbname='.$database, (string) getenv('DB_OWNER_USERNAME'), (string) getenv('DB_OWNER_PASSWORD'));
    $backend = HarnessFactory::integerQuery($pdo, 'SELECT pg_backend_pid()');
    $journal->append(new JournalEvent('child.backend', ['backend' => $backend], ProcessIdentity::current()));
    $barrier->arrive('ready');
    $barrier->await('release', 1, 15000);
    if ($mode === 'refund') {
        $cap = SimulatedCapabilities::snapshot('account-a');
        $driver = new DeterministicPaymentDriver($journal, new FixtureClock(new DateTimeImmutable('2026-11-17T00:00:00Z')), $cap, static fn (): int => 0);
        $id = $argv[3];
        $driver->refund(HarnessFactory::request($cap, 'refund-'.$id, 'refund-reference-'.$id, 5));
    }
    $journal->append(new JournalEvent('child.done', [], ProcessIdentity::current()));
} elseif ($mode === 'crash-after-accept') {
    $cap = SimulatedCapabilities::snapshot('account-a');
    $driver = new DeterministicPaymentDriver($journal, new FixtureClock(new DateTimeImmutable('2026-11-17T00:00:00Z')), $cap, static fn (): int => 0, checkpoint: static function (string $stage) use ($barrier): void {
        if ($stage === 'after_accept') {
            $barrier->arrive('accepted');
            $barrier->await('never-release', 1, 15000);
        }
    });
    $driver->execute(HarnessFactory::request($cap));
} else {
    throw new RuntimeException('Unknown harness child mode');
}
