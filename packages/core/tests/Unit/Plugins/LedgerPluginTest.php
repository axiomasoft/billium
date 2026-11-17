<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Unit\Plugins;

use Billium\Core\Plugins\LedgerPlugin;
use Billium\Core\Plugins\PlatformPlugin;
use Orchestra\Testbench\TestCase;
use Override;
use Packman\Extensions\ExtensionInspector;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Testkit\Concerns\Conformance\PluginContractTests;

final class LedgerPluginTest extends TestCase
{
    use PluginContractTests;

    #[Override]
    protected function pluginUnderTest(): ExtensionPlugin
    {
        return new LedgerPlugin;
    }

    /** @return list<class-string<ExtensionPlugin>> */
    protected function dependencyPluginClasses(): array
    {
        return [PlatformPlugin::class];
    }

    public function test_ledger_plugin_requires_platform_and_declares_ledger_tables(): void
    {
        $plugin = new LedgerPlugin;
        $profile = ExtensionInspector::inspect($plugin);

        self::assertSame('axiomasoft.billium.ledger', $plugin->manifest()->id);
        self::assertSame(['axiomasoft.billium.platform'], $plugin->manifest()->requires);
        self::assertTrue($profile->hasSchema);
        self::assertSame('billium_', $profile->tablePrefix);
        self::assertContains('obligation', $profile->tableNouns);
        self::assertContains('allocation', $profile->tableNouns);
    }
}
