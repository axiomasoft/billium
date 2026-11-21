<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Unit\Diagnostics;

use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Data\Modules\InstalledCheckpoint;
use Billium\Core\Data\Modules\MigrationIdentity;
use Billium\Core\Diagnostics\BilliumCompatibilityDoctorCheck;
use Billium\Core\Internal\Modules\ModuleCatalog;
use Illuminate\Config\Repository;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Packman\Doctor\Enums\CheckStatus;
use PHPUnit\Framework\TestCase;

final class BilliumCompatibilityDoctorCheckTest extends TestCase
{
    private const string KEY = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    public function test_invalid_configuration_is_the_only_finding(): void
    {
        $raw = self::config();
        $raw['runnr'] = [];

        $check = new BilliumCompatibilityDoctorCheck(new Repository(['billium' => $raw]), ModuleCatalog::packaged());
        $findings = $check->run(new DoctorContext(production: true));

        self::assertSame(['billium.config.invalid'], self::codes($findings));
        self::assertSame(CheckStatus::Error, $findings[0]->status);
        self::assertStringContainsString('billium.runnr', $findings[0]->message);
    }

    public function test_non_array_root_is_reported_as_invalid_configuration(): void
    {
        $check = new BilliumCompatibilityDoctorCheck(new Repository(['billium' => 'oops']), ModuleCatalog::packaged());

        self::assertSame(['billium.config.invalid'], self::codes($check->run(new DoctorContext)));
    }

    public function test_complete_development_setup_is_compatible(): void
    {
        $findings = $this->check(self::config(), ports: true)->run(new DoctorContext);

        self::assertSame(['billium.modules.compatibility'], self::codes($findings));
        self::assertSame(CheckStatus::Ok, $findings[0]->status);
    }

    public function test_unbound_ports_warn_in_development_and_fail_in_production(): void
    {
        $development = $this->check(self::config())->run(new DoctorContext);
        $production = $this->check(self::config())->run(new DoctorContext(production: true));

        self::assertNotEmpty($development);
        foreach ($development as $finding) {
            self::assertSame('billium.modules.module.port_missing', $finding->code);
            self::assertSame(CheckStatus::Warning, $finding->status);
            self::assertNotNull($finding->slice);
        }
        self::assertSame(self::codes($development), self::codes($production));
        foreach ($production as $finding) {
            self::assertSame(CheckStatus::Error, $finding->status);
        }
    }

    public function test_missing_signing_key_is_strict_only_in_production(): void
    {
        $raw = self::config();
        $raw['feed']['signing_key'] = null;

        $development = $this->check($raw, ports: true, appKey: null)->run(new DoctorContext);
        $production = $this->check($raw, ports: true, appKey: null)->run(new DoctorContext(production: true));

        self::assertSame(['billium.feed.signing_key_missing'], self::codes($development));
        self::assertNotSame(CheckStatus::Error, $development[0]->status);
        self::assertSame(['billium.feed.signing_key_missing'], self::codes($production));
        self::assertSame(CheckStatus::Error, $production[0]->status);
    }

    public function test_short_signing_key_is_an_error(): void
    {
        $raw = self::config();
        $raw['feed']['signing_key'] = 'base64:'.base64_encode(str_repeat('k', 31));

        $findings = $this->check($raw, ports: true)->run(new DoctorContext);

        self::assertSame(['billium.feed.signing_key_too_short'], self::codes($findings));
        self::assertSame(CheckStatus::Error, $findings[0]->status);
    }

    public function test_disabled_runtime_role_preflight_is_flagged_in_production_only(): void
    {
        $raw = self::config();
        $raw['runtime_role']['preflight'] = false;

        self::assertSame(['billium.modules.compatibility'], self::codes($this->check($raw, ports: true)->run(new DoctorContext)));
        self::assertSame(
            ['billium.runtime_role.preflight_disabled'],
            self::codes($this->check($raw, ports: true)->run(new DoctorContext(production: true))),
        );
    }

    public function test_production_without_a_database_cannot_prove_installed_checkpoints(): void
    {
        $findings = $this->check(self::config(), ports: true, checkpoints: false)->run(new DoctorContext(production: true));

        self::assertSame(['billium.database.unavailable'], self::codes($findings));
        self::assertSame(CheckStatus::Error, $findings[0]->status);
    }

    public function test_development_without_a_database_assumes_the_packaged_checkpoints(): void
    {
        $findings = $this->check(self::config(), ports: true, checkpoints: false)->run(new DoctorContext);

        self::assertSame(['billium.modules.compatibility'], self::codes($findings));
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        /** @var array<string, mixed> $raw */
        $raw = require dirname(__DIR__, 3).'/config/billium.php';

        return $raw;
    }

    /** @param array<string, mixed> $raw */
    private function check(array $raw, bool $ports = false, bool $checkpoints = true, ?string $appKey = self::KEY): BilliumCompatibilityDoctorCheck
    {
        $options = BilliumOptions::fromArray($raw, 'billium');
        $catalog = ModuleCatalog::packaged();
        $required = [];
        $installed = [];
        foreach ($catalog->manifest()->modules as $module) {
            if (in_array($module->moduleId, $options->modules->enabled, true)) {
                array_push($required, ...$module->requiresPorts);
            }
            $installed[$module->moduleId] = new InstalledCheckpoint(
                moduleId: $module->moduleId,
                schemaVersion: $module->currentSchemaVersion,
                appliedMigrations: array_map(static fn (MigrationIdentity $migration): string => $migration->id, $module->migrationIdentities),
            );
        }

        return new BilliumCompatibilityDoctorCheck(
            new Repository(['app' => ['key' => $appKey], 'billium' => $raw]),
            $catalog,
            optionsResolver: static fn (): BilliumOptions => $options,
            portsResolver: $ports ? static fn (): array => array_values(array_unique($required)) : null,
            checkpointsResolver: $checkpoints ? static fn (): array => $installed : null,
        );
    }

    /**
     * @param  list<DoctorFinding>  $findings
     * @return list<string>
     */
    private static function codes(array $findings): array
    {
        return array_map(static fn (DoctorFinding $finding): string => $finding->code, $findings);
    }
}
