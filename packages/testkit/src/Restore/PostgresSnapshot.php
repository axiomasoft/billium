<?php

declare(strict_types=1);

namespace Billium\Testkit\Restore;

use Packman\Attributes\Api;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;

/** PostgreSQL logical backup of a leased, role-separated disposable test database. Restores only into new randomly named databases. */
#[Api(since: '1.0')]
final class PostgresSnapshot
{
    /** @var list<string> */
    private array $created = [];

    public readonly string $sha256;

    public function __construct(private readonly PDO $owner, private readonly string $database, private readonly string $file)
    {
        $databaseQuery = $owner->query('SELECT current_database()');
        $leaseQuery = $owner->query("SELECT count(*) FROM pg_locks WHERE locktype='advisory' AND granted AND classid=1112099916 AND objid=1413829460 AND database=(SELECT oid FROM pg_database WHERE datname=current_database())");
        if (! str_ends_with($database, '_test') || $databaseQuery === false || $databaseQuery->fetchColumn() !== $database
            || $leaseQuery === false || (int) $leaseQuery->fetchColumn() < 1) {
            throw new RuntimeException('Disposable PostgreSQL database and active suite lease required');
        }
        $this->run(['pg_dump', '--version']);
        $this->run(['pg_restore', '--version']);
        $this->run(['pg_dump', '--format=custom', '--file='.$file, '--dbname='.$database]);
        $digest = hash_file('sha256', $file);
        $this->sha256 = is_string($digest) ? $digest : throw new RuntimeException('Snapshot digest unavailable');
        fwrite(STDOUT, json_encode(['dumpFile' => $file, 'database' => $database, 'sha256' => $this->sha256], JSON_THROW_ON_ERROR)."\n");
    }

    public function restore(): string
    {
        $name = 'billium_restore_'.bin2hex(random_bytes(8)).'_test';
        $this->owner->exec('CREATE DATABASE "'.$name.'" TEMPLATE template0');
        $this->created[] = $name;
        $this->run(['pg_restore', '--exit-on-error', '--single-transaction', '--dbname='.$name, $this->file]);

        return $name;
    }

    public function close(): void
    {
        foreach ($this->created as $name) {
            $this->owner->exec('DROP DATABASE "'.$name.'" WITH (FORCE)');
        }
        $this->created = [];
    }

    /** @param list<string> $command */
    private function run(array $command): void
    {
        $env = [];
        foreach (['PGHOST' => 'DB_HOST', 'PGPORT' => 'DB_PORT', 'PGUSER' => 'DB_OWNER_USERNAME', 'PGPASSWORD' => 'DB_OWNER_PASSWORD'] as $pg => $key) {
            $value = getenv($key);
            $env[$pg] = is_string($value) && $value !== '' ? $value : throw new RuntimeException('Missing test database environment');
        }
        $process = new Process($command, env: $env, timeout: 90);
        $process->mustRun();
        fwrite(STDOUT, json_encode(['pgCommand' => $command, 'exit' => $process->getExitCode(), 'output' => trim($process->getOutput())], JSON_THROW_ON_ERROR)."\n");
    }
}
