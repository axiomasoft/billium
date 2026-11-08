<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Unit\Modules;

use Billium\Core\Data\Modules\ModuleDescriptor;
use Billium\Core\Data\Modules\ModuleManifest;
use Billium\Core\Data\Modules\PublicEventDeclaration;
use Billium\Core\Enums\Modules\CompatibilityFindingCode;
use Billium\Core\Internal\Modules\ModuleCatalog;
use Billium\Core\Tests\Arch\MigrationTables;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ModuleDescriptorTest extends ModuleTestCase
{
    public function test_packaged_registry_declares_the_eight_core_modules_with_stable_ids(): void
    {
        $manifest = ModuleCatalog::packaged()->manifest();

        self::assertSame(
            ['platform', 'ledger', 'usage', 'rating', 'schedule', 'documents', 'execution', 'collection'],
            array_map(static fn (ModuleDescriptor $module): string => $module->moduleId, $manifest->modules),
        );
        foreach ($manifest->modules as $module) {
            self::assertSame($module->moduleId, $module->migrationStream);
            self::assertSame('billium.'.$module->moduleId, $module->schemaNamespace);
        }
    }

    public function test_packaged_resource_equals_the_normative_source(): void
    {
        $source = dirname(__DIR__, 5).'/spec/modules.json';
        self::assertFileExists($source);
        self::assertSame(
            json_decode((string) file_get_contents($source), true, 64, JSON_THROW_ON_ERROR),
            self::document(),
        );
    }

    public function test_descriptors_never_pretend_future_sql_was_applied(): void
    {
        $owners = MigrationTables::ownership(dirname(__DIR__, 3).'/database/migrations');
        foreach (ModuleCatalog::packaged()->manifest()->modules as $module) {
            $tables = array_keys(array_filter($owners, static fn (string $owner): bool => $owner === $module->moduleId));
            sort($tables);
            $declared = $module->ownedTables;
            sort($declared);
            self::assertSame($tables, $declared, $module->moduleId.' ownedTables follow its migrations');
            self::assertSame(count($module->migrationIdentities), $module->currentSchemaVersion);
        }
    }

    public function test_ledger_declares_exactly_the_strict_v1_event_types(): void
    {
        $ledger = ModuleCatalog::packaged()->descriptor('ledger');
        self::assertNotNull($ledger);
        $types = array_map(static fn (PublicEventDeclaration $event): string => $event->eventType, $ledger->publicEvents);
        sort($types);
        $strict = PublicEventDeclaration::STRICT_V1_TYPES;
        sort($strict);
        self::assertSame($strict, $types);
    }

    public function test_optional_modules_declare_no_unconditional_external_requirement(): void
    {
        $manifest = ModuleCatalog::packaged()->manifest();
        foreach (['usage', 'rating', 'schedule', 'documents'] as $id) {
            $module = $manifest->descriptor($id);
            self::assertNotNull($module);
            foreach ($module->requiredModuleIds() as $required) {
                self::assertNotNull($manifest->descriptor($required), 'internal dependencies only');
            }
        }
        $platform = $manifest->descriptor('platform');
        $ledger = $manifest->descriptor('ledger');
        self::assertNotNull($platform);
        self::assertNotNull($ledger);
        self::assertSame([], $platform->requiredModuleIds());
        self::assertSame(['platform'], $ledger->requiredModuleIds(), 'minimal platform+ledger do not need documents/collection/execution');
    }

    /** @return iterable<string, array{string}> */
    public static function requiredMembers(): iterable
    {
        foreach ([
            'moduleId', 'schemaNamespace', 'moduleApiVersion', 'coreApiRange', 'migrationStream', 'currentSchemaVersion',
            'supportedSchemaReadRange', 'supportedSchemaWriteRange', 'requiresModules', 'requiresPorts', 'optionalPorts',
            'emits', 'consumes', 'ownedTables', 'configKeys', 'migrationIdentities', 'disablePolicy', 'retirementPolicy',
            'uninstallPolicy', 'retentionClass', 'publicEvents', 'internalCommands',
        ] as $member) {
            yield $member => [$member];
        }
    }

    #[DataProvider('requiredMembers')]
    public function test_incomplete_manifest_fails_before_any_admission(string $member): void
    {
        $document = self::document();
        $module = $document['modules'][0];
        unset($module[$member]);
        $document['modules'][0] = $module;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("missing member {$member}");
        ModuleManifest::fromArray($document);
    }

    public function test_unknown_member_and_malformed_values_are_rejected(): void
    {
        foreach ([
            ['plugin' => 'https://example.test/x'],
            ['moduleId' => 'Bad-Id'],
            ['moduleApiVersion' => '1.0'],
            ['coreApiRange' => ['min' => '2.0.0', 'maxExclusive' => '1.0.0']],
            ['supportedSchemaReadRange' => ['min' => 0, 'max' => 0], 'supportedSchemaWriteRange' => ['min' => 0, 'max' => 1]],
            ['currentSchemaVersion' => 3],
            ['ownedTables' => ['users']],
            ['ownedTables' => ['billium_a', 'billium_a']],
            ['schemaNamespace' => 'app.platform'],
        ] as $broken) {
            try {
                ModuleManifest::fromArray(self::withModule(self::document(), 'platform', $broken));
                self::fail('accepted '.json_encode($broken));
            } catch (InvalidArgumentException $exception) {
                self::assertStringStartsWith('module.descriptor_invalid:', $exception->getMessage());
            }
        }
    }

    public function test_disable_retirement_and_uninstall_policies_cannot_be_weakened(): void
    {
        $ledger = self::document()['modules'][1];
        foreach ([
            ['uninstallPolicy' => [...$ledger['uninstallPolicy'], 'schemaPurge' => 'automatic']],
            ['uninstallPolicy' => [...$ledger['uninstallPolicy'], 'readBundleRequiredWithHistory' => false]],
            ['disablePolicy' => [...$ledger['disablePolicy'], 'keeps' => ['new_intent', 'receipt_replay', 'historical_read']]],
            ['disablePolicy' => [...$ledger['disablePolicy'], 'keeps' => ['unknown_resolution']]],
            ['retirementPolicy' => ['blockers' => ['pending_operations']]],
        ] as $broken) {
            try {
                ModuleManifest::fromArray(self::withModule(self::document(), 'ledger', $broken));
                self::fail('accepted '.json_encode($broken));
            } catch (InvalidArgumentException $exception) {
                self::assertStringStartsWith('module.descriptor_invalid:', $exception->getMessage());
            }
        }
    }

    public function test_finding_codes_equal_the_enum(): void
    {
        $codes = array_map(
            static fn (CompatibilityFindingCode $code): string => $code->value,
            CompatibilityFindingCode::cases(),
        );
        self::assertSame(ModuleCatalog::packaged()->manifest()->findingCodes, $codes);
    }
}
