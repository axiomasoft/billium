<?php

declare(strict_types=1);

namespace Billium\Core\Internal\Modules;

use Billium\Core\Data\Modules\ModuleDescriptor;
use Illuminate\Container\Container;
use LogicException;
use Override;
use Packman\Attributes\Internal;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use Packman\Plugins\Testing\PluginGraph;

/**
 * Adapts arbitrary ModuleDescriptor graphs onto Packman's PluginGraph for Kahn ordering and cycle detection.
 */
#[Internal]
final class ModuleGraphPluginBridge implements ExtensionPlugin
{
    /** @var list<PluginManifest> */
    private static array $pending = [];

    private readonly PluginManifest $pluginManifest;

    public function __construct()
    {
        $manifest = array_shift(self::$pending);
        if (! $manifest instanceof PluginManifest) {
            throw new LogicException('ModuleGraphPluginBridge must only be constructed inside sort()');
        }
        $this->pluginManifest = $manifest;
    }

    /**
     * @param  array<string, ModuleDescriptor>  $nodes
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function sort(array $nodes): array
    {
        if ($nodes === []) {
            return [[], []];
        }

        $pluginIds = [];
        $reverseMap = [];
        $position = 0;
        foreach (array_keys($nodes) as $moduleId) {
            $pluginId = sprintf('billium.node-%04d', $position++);
            $pluginIds[$moduleId] = $pluginId;
            $reverseMap[$pluginId] = $moduleId;
        }

        $manifests = [];
        foreach ($nodes as $moduleId => $descriptor) {
            $requires = [];
            foreach ($descriptor->requiredModuleIds() as $requiredId) {
                if ($requiredId === $moduleId) {
                    return [[], [$moduleId]];
                }
                if (isset($pluginIds[$requiredId])) {
                    $requires[] = $pluginIds[$requiredId];
                }
            }
            $manifests[] = new PluginManifest(
                id: $pluginIds[$moduleId],
                version: '1.0.0',
                requires: array_values(array_unique($requires)),
            );
        }

        self::$pending = $manifests;
        try {
            $compiled = PluginGraph::compile(
                new Container,
                [],
                builtIn: array_fill(0, count($manifests), self::class),
            );
        } catch (InvalidConfiguration $exception) {
            if (preg_match('/dependency cycle among (.+)$/', $exception->getMessage(), $matches) === 1) {
                $cycle = [];
                foreach (explode(', ', $matches[1]) as $pluginId) {
                    $pluginId = trim($pluginId);
                    $cycle[] = $reverseMap[$pluginId] ?? $pluginId;
                }

                return [[], $cycle];
            }

            return [[], array_keys($nodes)];
        } finally {
            self::$pending = [];
        }

        $order = [];
        foreach ($compiled->describe() as $description) {
            $order[] = $reverseMap[$description->id] ?? $description->id;
        }

        return [$order, []];
    }

    #[Override]
    public function manifest(): PluginManifest
    {
        return $this->pluginManifest;
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void {}
}
