<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Unit\Modules;

use Billium\Core\Data\Modules\CompatibilityInput;
use Billium\Core\Data\Modules\InstalledCheckpoint;
use Billium\Core\Enums\Modules\CompatibilityFindingCode as Code;

final class ModuleCompositionTest extends ModuleTestCase
{
    public function test_default_desired_config_is_a_valid_minimal_platform_ledger_composition(): void
    {
        $config = require dirname(__DIR__, 3).'/config/billium.php';
        $report = self::catalog()->check(CompatibilityInput::fromConfig($config['modules'], self::LEDGER_PORTS, self::migrated('platform', 'ledger')));

        self::assertTrue($report->isReady(), json_encode($report->findings, JSON_THROW_ON_ERROR));
        self::assertSame(['platform', 'ledger'], $report->bootOrder);
    }

    public function test_disabled_optional_modules_need_no_external_dependency(): void
    {
        // documents needs DocumentRenderer, execution needs PSP drivers: none is demanded while they are off.
        $report = self::catalog()->check(self::input('embedded-commerz', ['platform', 'ledger']));

        self::assertTrue($report->isReady());
        self::assertFalse($report->has(Code::PortMissing, 'documents'));
    }

    public function test_every_supported_composition_has_a_valid_example(): void
    {
        $catalog = self::catalog();
        $ports = [...self::LEDGER_PORTS, ...self::EXECUTION_PORTS, 'CollectionConsentGuard'];
        $cases = [
            'embedded-commerz' => [['platform', 'ledger', 'usage', 'rating', 'schedule', 'documents'], [...self::LEDGER_PORTS, 'DocumentRenderer']],
            'ledger-only' => [['platform', 'ledger', 'documents'], [...self::LEDGER_PORTS, 'DocumentRenderer']],
            'standalone-billium' => [['platform', 'ledger', 'execution', 'collection'], $ports],
            'remote-billium' => [['platform', 'ledger', 'execution'], $ports],
        ];
        foreach ($cases as $composition => [$enabled, $provided]) {
            $report = $catalog->check(self::input($composition, $enabled, $provided));
            self::assertTrue($report->isReady(), $composition.': '.json_encode($report->findings, JSON_THROW_ON_ERROR));
        }
    }

    public function test_boot_order_follows_the_dag(): void
    {
        $report = self::catalog()->check(self::input(
            'standalone-billium',
            ['collection', 'execution', 'ledger', 'platform'],
            [...self::LEDGER_PORTS, ...self::EXECUTION_PORTS, 'CollectionConsentGuard'],
        ));

        self::assertTrue($report->isReady());
        self::assertSame(['platform', 'ledger', 'execution', 'collection'], $report->bootOrder);
    }

    public function test_execution_admission_binding_is_required_and_forbidden_where_the_row_says_so(): void
    {
        $withoutWriter = self::catalog()->check(self::input('remote-billium', ['platform', 'ledger', 'execution'], [...self::LEDGER_PORTS, 'PaymentDrivers']));
        self::assertTrue($withoutWriter->has(Code::PortMissing, 'execution'));

        $ledgerOnlyWithWriter = self::catalog()->check(self::input('ledger-only', ['platform', 'ledger'], [...self::LEDGER_PORTS, 'WriterAdmission']));
        self::assertTrue($ledgerOnlyWithWriter->has(Code::PortForbidden));
    }

    public function test_composition_rows_and_dependencies_are_enforced(): void
    {
        $catalog = self::catalog();

        self::assertTrue($catalog->check(self::input('embedded-commerz', ['platform', 'ledger', 'execution']))->has(Code::CompositionForbidden, 'execution'));
        self::assertTrue($catalog->check(self::input('embedded-commerz', ['platform']))->has(Code::CompositionRequired, 'ledger'));
        self::assertTrue($catalog->check(self::input('embedded-commerz', ['platform', 'ledger', 'schedule']))->has(Code::DependencyMissing, 'schedule'));
        self::assertTrue($catalog->check(self::input('standalone-billium', ['platform', 'ledger', 'collection'], [...self::LEDGER_PORTS, 'CollectionConsentGuard']))->has(Code::DependencyMissing, 'collection'));
        self::assertTrue($catalog->check(self::input('ledger-only', ['platform', 'ledger', 'execution']))->has(Code::CompositionForbidden, 'execution'));
    }

    public function test_unknown_duplicate_and_unsupported_inputs_are_findings(): void
    {
        $catalog = self::catalog();

        self::assertTrue($catalog->check(self::input('embedded-commerz', ['platform', 'ledger', 'plugin_x']))->has(Code::Unknown, 'plugin_x'));
        self::assertTrue($catalog->check(self::input('embedded-commerz', ['platform', 'ledger', 'ledger']))->has(Code::DuplicateId, 'ledger'));
        self::assertTrue($catalog->check(self::input('mystery', ['platform', 'ledger']))->has(Code::CompositionUnknown));
        self::assertTrue($catalog->check(self::input('embedded-commerz', ['platform', 'ledger'], []))->has(Code::PortMissing, 'ledger'));
    }

    public function test_cycle_range_and_core_api_failures(): void
    {
        $cycle = self::catalog(fn (array $d): array => self::withModule($d, 'platform', [
            'requiresModules' => [['moduleId' => 'ledger', 'apiRange' => ['min' => '1.0.0', 'maxExclusive' => '2.0.0']]],
        ]))->check(self::input('embedded-commerz', ['platform', 'ledger']));
        self::assertTrue($cycle->has(Code::DependencyCycle));
        self::assertSame([], $cycle->bootOrder);

        $range = self::catalog(fn (array $d): array => self::withModule($d, 'ledger', [
            'requiresModules' => [['moduleId' => 'platform', 'apiRange' => ['min' => '2.0.0', 'maxExclusive' => '3.0.0']]],
        ]))->check(self::input('embedded-commerz', ['platform', 'ledger']));
        self::assertTrue($range->has(Code::DependencyRange, 'ledger'));

        $missing = self::catalog(fn (array $d): array => self::withModule($d, 'ledger', [
            'requiresModules' => [['moduleId' => 'ghost', 'apiRange' => ['min' => '1.0.0', 'maxExclusive' => '2.0.0']]],
        ]))->check(self::input('embedded-commerz', ['platform', 'ledger']));
        self::assertTrue($missing->has(Code::DependencyMissing, 'ledger'));

        $core = self::catalog(fn (array $d): array => [...$d, 'coreApiVersion' => '2.0.0'])
            ->check(self::input('embedded-commerz', ['platform', 'ledger']));
        self::assertTrue($core->has(Code::CoreApiUnsupported, 'ledger'));

        $port = self::catalog(fn (array $d): array => self::withModule($d, 'usage', ['requiresPorts' => ['Nope']]))
            ->check(self::input('embedded-commerz', ['platform', 'ledger']));
        self::assertTrue($port->has(Code::PortUnknown, 'usage'));
    }

    public function test_ownership_and_event_contract_conflicts_between_modules(): void
    {
        $tables = self::catalog(fn (array $d): array => self::withModule(self::withModule($d, 'usage', ['ownedTables' => ['billium_x']]), 'rating', ['ownedTables' => ['billium_x']]))
            ->check(self::input('embedded-commerz', ['platform', 'ledger']));
        self::assertTrue($tables->has(Code::OwnershipConflict, 'rating'));

        $keys = self::catalog(fn (array $d): array => self::withModule($d, 'usage', ['configKeys' => ['billium.ledger.refund_reconcile_after_seconds']]))
            ->check(self::input('embedded-commerz', ['platform', 'ledger']));
        self::assertTrue($keys->has(Code::OwnershipConflict, 'usage'));

        $event = self::document()['modules'][1]['publicEvents'][0];
        $changed = self::catalog(fn (array $d): array => self::withModule($d, 'usage', [
            'publicEvents' => [[...$event, 'payloadSchemaDigest' => 'sha256:'.str_repeat('0', 64)]],
        ]))->check(self::input('embedded-commerz', ['platform', 'ledger']));
        self::assertTrue($changed->has(Code::EventContractConflict, 'usage'));
    }

    public function test_schema_ranges_checkpoints_and_n_minus_one_window(): void
    {
        $catalog = self::catalog(fn (array $d): array => self::withModule($d, 'ledger', [
            'currentSchemaVersion' => 2,
            'supportedSchemaReadRange' => ['min' => 1, 'max' => 2],
            'supportedSchemaWriteRange' => ['min' => 2, 'max' => 2],
            'migrationIdentities' => [
                ['id' => '2026_10_30_000001_a', 'version' => 1, 'checksum' => 'sha256:'.str_repeat('a', 64)],
                ['id' => '2026_10_30_000002_b', 'version' => 2, 'checksum' => 'sha256:'.str_repeat('b', 64)],
            ],
        ]));
        $applied = ['2026_10_30_000001_a', '2026_10_30_000002_b'];
        $check = static fn (InstalledCheckpoint $checkpoint) => $catalog->check(
            new CompatibilityInput('embedded-commerz', ['platform', 'ledger'], self::LEDGER_PORTS, ['ledger' => $checkpoint] + self::migrated('platform')),
        );

        self::assertTrue($check(new InstalledCheckpoint('ledger', 2, $applied))->isReady());

        $uninstalled = $check(new InstalledCheckpoint('ledger'));
        self::assertTrue($uninstalled->has(Code::MigrationUnapplied, 'ledger'));
        self::assertTrue($uninstalled->has(Code::SchemaReadUnsupported, 'ledger'));

        $previous = $check(new InstalledCheckpoint('ledger', 1, [$applied[0]]));
        self::assertTrue($previous->has(Code::MigrationUnapplied, 'ledger'));
        self::assertTrue($previous->has(Code::SchemaWriteUnsupported, 'ledger'), 'N-1 reads but does not write the expanded schema');
        self::assertFalse($previous->has(Code::SchemaReadUnsupported, 'ledger'));

        self::assertTrue($check(new InstalledCheckpoint('ledger', 3, $applied))->has(Code::SchemaReadUnsupported, 'ledger'));
        self::assertTrue($check(new InstalledCheckpoint('ledger', 2, [$applied[1]]))->has(Code::MigrationUnapplied, 'ledger'));
    }

    public function test_history_without_a_historical_reader_is_a_finding(): void
    {
        $report = self::catalog()->check(new CompatibilityInput('embedded-commerz', ['platform', 'ledger'], self::LEDGER_PORTS, [], ['execution'], ['ledger']));

        self::assertTrue($report->has(Code::HistoricalReaderMissing, 'execution'));
        self::assertFalse($report->has(Code::HistoricalReaderMissing, 'ledger'));
    }

    public function test_descriptor_set_findings_do_not_depend_on_what_is_enabled(): void
    {
        $catalog = self::catalog(fn (array $d): array => self::withModule($d, 'collection', ['requiresPorts' => ['Nope']]));

        self::assertTrue($catalog->check(self::input('embedded-commerz', ['platform', 'ledger']))->has(Code::PortUnknown, 'collection'));
    }

    public function test_duplicate_module_ids_in_the_descriptor_set_are_reported(): void
    {
        $catalog = self::catalog(function (array $d): array {
            $d['modules'][] = $d['modules'][0];

            return $d;
        });

        self::assertTrue($catalog->check(self::input('embedded-commerz', ['platform', 'ledger']))->has(Code::DuplicateId, 'platform'));
    }
}
