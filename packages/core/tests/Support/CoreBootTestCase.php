<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Support;

use Illuminate\Contracts\Config\Repository;
use Override;
use Packman\Testkit\PackageTestCase;

/** Boots PostgreSQL-only plugins while keeping SQLite available for explicit fixture queries. */
abstract class CoreBootTestCase extends PackageTestCase
{
    protected const array POSTGRESQL_BOOT_DATABASE = [
        'default' => 'pgsql',
        'connections' => ['pgsql' => ['driver' => 'pgsql', 'database' => 'billium_test']],
    ];

    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->make(Repository::class)->set('database.default', 'pgsql');
    }
}
