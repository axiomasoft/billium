<?php

declare(strict_types=1);

namespace Billium\Testkit\Fiscal;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Contracts\Fiscal\FiscalDriver;
use Billium\Execution\Contracts\Fiscal\FiscalDrivers;
use Override;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Composition\ProvidesCapabilities;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * Minimal COV-295 fiscal integration extension stub proving capability/version negotiation
 * without implementing WithSchema or touching any core/platform/ledger/execution tables.
 */
#[Api(since: '1.0')]
final class FiscalIntegrationExtension implements ExtensionPlugin, WithCapabilities
{
    use ProvidesCapabilities;

    /** @var list<string> */
    private const array DECLARED_CONTRACT_VERSIONS = [
        'fiscal-driver.v1',
        'psp-passthrough.v1',
    ];

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'axiomasoft.billium.testkit.fiscal-extension',
            version: '1.0.0',
            requires: ['axiomasoft.billium.execution'],
            optional: [],
            resourceRoot: dirname(__DIR__, 2),
            databases: ['pgsql'],
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void {}

    /** @return array<class-string, class-string> */
    #[Override]
    public function providedCapabilities(): array
    {
        return [
            FiscalDrivers::class => SimulatedFiscalDrivers::class,
        ];
    }

    /** @return list<string> */
    public function declaredContractVersions(): array
    {
        return self::DECLARED_CONTRACT_VERSIONS;
    }

    public function negotiateDriver(FiscalDrivers $drivers, string $driverId, string $requiredContractVersion): FiscalDriver
    {
        if (! in_array($requiredContractVersion, self::DECLARED_CONTRACT_VERSIONS, true)) {
            throw new ProblemException(
                ProblemCode::CapabilityUnsupported,
                "Extension does not declare fiscal contract version {$requiredContractVersion}",
            );
        }

        return $drivers->resolve($driverId, $requiredContractVersion);
    }
}
