<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Unit\Plugins;

use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Plugins\PlatformPlugin;
use Orchestra\Testbench\TestCase;
use Override;
use Packman\Extensions\ExtensionInspector;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Testkit\Concerns\Conformance\PluginContractTests;

final class PlatformPluginTest extends TestCase
{
    use PluginContractTests;

    #[Override]
    protected function pluginUnderTest(): ExtensionPlugin
    {
        return new PlatformPlugin;
    }

    public function test_platform_plugin_declares_configuration_schema_slots_and_diagnostics(): void
    {
        $plugin = new PlatformPlugin;
        $profile = ExtensionInspector::inspect($plugin);

        self::assertSame('axiomasoft.billium.platform', $plugin->manifest()->id);
        self::assertSame(['pgsql'], $plugin->manifest()->databases);
        self::assertSame('billium', $profile->configRoot);
        self::assertSame(BilliumOptions::class, $profile->optionsClass);
        self::assertTrue($profile->hasSchema);
        self::assertSame('billium_', $profile->tablePrefix);
        self::assertContains('idempotency_receipt', $profile->tableNouns);
        self::assertNotEmpty($profile->healthChecks);
        self::assertNotEmpty($profile->problemProviders);
    }
}
