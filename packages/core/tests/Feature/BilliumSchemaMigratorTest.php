<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Feature;

use Billium\Core\Tests\DatabaseTestCase;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use Packman\Schema\Enums\SchemaScope;
use Packman\Schema\Testing\MigratesSchemaSets;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class BilliumSchemaMigratorTest extends DatabaseTestCase
{
    use MigratesSchemaSets;

    public function test_packman_schema_migrator_tracks_billium_platform_and_ledger_sets(): void
    {
        $statuses = $this->app->make(SchemaMigrator::class)->status(SchemaScope::Central, 'billium_owner');
        $byId = [];
        foreach ($statuses as $status) {
            $byId[$status->set->id()] = $status;
        }

        self::assertArrayHasKey('axiomasoft/billium-core:billium-platform', $byId);
        self::assertArrayHasKey('axiomasoft/billium-core:billium-ledger', $byId);

        self::assertSame([], $byId['axiomasoft/billium-core:billium-platform']->pending);
        self::assertSame([], $byId['axiomasoft/billium-core:billium-ledger']->pending);
        self::assertCount(16, $byId['axiomasoft/billium-core:billium-platform']->applied);
        self::assertCount(10, $byId['axiomasoft/billium-core:billium-ledger']->applied);
    }
}
