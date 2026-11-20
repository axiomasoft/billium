<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Feature;

use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Data\Modules\MigrationIdentity;
use Billium\Core\Tests\DatabaseTestCase;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;
use Packman\Schema\Testing\MigratesSchemaSets;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class BilliumSchemaMigratorTest extends DatabaseTestCase
{
    use MigratesSchemaSets;

    public function test_packman_schema_migrator_tracks_billium_platform_and_module_sets(): void
    {
        self::assertNotNull($this->app);
        $sets = $this->app->make(SchemaSets::class);
        $platformOnly = $sets->forScope(SchemaScope::Central, includeModules: false);
        self::assertSame(['axiomasoft/billium-core:billium-platform'], array_map(static fn ($set): string => $set->id(), $platformOnly));

        $statuses = $this->app->make(SchemaMigrator::class)->status(SchemaScope::Central, 'billium_owner');
        $byId = [];
        foreach ($statuses as $status) {
            $byId[$status->set->id()] = $status;
        }

        $modules = $this->app->make(ModuleRegistry::class);
        $lifecycles = [
            'platform' => SchemaLifecycle::Platform,
            'ledger' => SchemaLifecycle::Module,
            'usage' => SchemaLifecycle::Module,
            'schedule' => SchemaLifecycle::Module,
            'rating' => SchemaLifecycle::Module,
            'documents' => SchemaLifecycle::Module,
            'execution' => SchemaLifecycle::Module,
            'collection' => SchemaLifecycle::Module,
        ];

        foreach ($lifecycles as $module => $lifecycle) {
            $setId = 'axiomasoft/billium-core:billium-'.$module;
            self::assertArrayHasKey($setId, $byId);
            self::assertSame($lifecycle, $byId[$setId]->set->lifecycle, $setId);

            $descriptor = $modules->descriptor($module);
            self::assertNotNull($descriptor, $setId);

            $expected = array_map(static fn (MigrationIdentity $identity): string => $identity->id, $descriptor->migrationIdentities);
            $applied = $byId[$setId]->applied;
            sort($expected, SORT_STRING);
            sort($applied, SORT_STRING);
            self::assertNotEmpty($expected, $setId);
            self::assertSame($expected, $applied, $setId);
            self::assertSame([], $byId[$setId]->pending, $setId);
            self::assertSame([], $byId[$setId]->unknown, $setId);
        }
    }
}
