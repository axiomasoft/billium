<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Support\Concurrency;

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use RuntimeException;

/** Reports the database WorkerApplication actually connected to. */
final readonly class ReportDatabaseScenario implements Scenario
{
    /** @param array<string, mixed> $args */
    public function __construct(Application $app, int $index, array $args)
    {
        if ($index < 0 || ! $app->bound('db') || ! is_array($args)) {
            throw new RuntimeException('Worker application is not booted');
        }
    }

    public function run(Application $app, int $index, array $args): array
    {
        $database = $app->make(DatabaseManager::class)->connection('pgsql')->scalar('SELECT current_database()');

        return ['database' => is_string($database) ? $database : ''];
    }
}
