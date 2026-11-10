<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Unit\Modules;

use Illuminate\Database\Migrations\Migration;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class P4MigrationSafetyTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function migrations(): iterable
    {
        foreach (['usage', 'rating', 'schedule'] as $module) {
            foreach (glob(dirname(__DIR__, 5).'/packages/core/database/migrations/'.$module.'/2026_11_10_*.php') ?: [] as $path) {
                yield basename($path) => [$path];
            }
        }
    }

    #[DataProvider('migrations')]
    public function test_p4_migrations_refuse_destructive_rollback(string $path): void
    {
        $migration = require $path;
        self::assertInstanceOf(Migration::class, $migration);
        $rollback = [$migration, 'down'];
        self::assertIsCallable($rollback);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('forward-only (REQ-FAIL-29)');
        $rollback();
    }
}
