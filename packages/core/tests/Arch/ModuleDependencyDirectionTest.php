<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Arch;

use FilesystemIterator;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A module may only name Billium packages its composer.json requires, and every required Billium package must be used.
 * Composer cannot enforce this across a path-repository monorepo, so an undeclared import would otherwise only fail
 * once the module is installed on its own.
 */
final class ModuleDependencyDirectionTest extends TestCase
{
    public function test_module_sources_only_import_declared_billium_packages(): void
    {
        $violations = [];
        foreach (self::modules() as $module => $declared) {
            $used = self::usedPackages(dirname(__DIR__, 2).'/modules/'.$module.'/src');
            foreach ($used as $package => $file) {
                if ($package !== $module && ! in_array($package, $declared, true)) {
                    $violations[] = $module.' imports undeclared billium-'.$package.' ('.$file.')';
                }
            }
            foreach ($declared as $package) {
                if (! isset($used[$package])) {
                    $violations[] = $module.' declares billium-'.$package.' but never imports it';
                }
            }
        }
        self::assertSame([], $violations);
    }

    public function test_declared_module_dependencies_are_acyclic(): void
    {
        $graph = self::modules();
        $state = [];
        $visit = static function (string $module, array $path) use (&$visit, &$state, $graph): void {
            if (($state[$module] ?? null) === 'done') {
                return;
            }
            self::assertNotSame('open', $state[$module] ?? null, 'module dependency cycle: '.implode(' -> ', [...$path, $module]));
            $state[$module] = 'open';
            foreach ($graph[$module] ?? [] as $dependency) {
                $visit($dependency, [...$path, $module]);
            }
            $state[$module] = 'done';
        };
        foreach (array_keys($graph) as $module) {
            $visit($module, []);
        }
        self::assertSame(array_fill_keys(array_keys($graph), 'done'), array_intersect_key($state, $graph));
    }

    public function test_used_packages_resolve_aliases_and_fully_qualified_names(): void
    {
        $directory = sys_get_temp_dir().'/billium-dependency-direction-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        file_put_contents($directory.'/Probe.php', <<<'PHP'
            <?php
            namespace Billium\Rating\Internal;
            use Billium\Ledger as L;
            final class Probe { public function a(): L\Contracts\LedgerQueries { return new \Billium\Usage\Data\WindowSelector(); } }
            PHP);
        try {
            self::assertSame(['rating', 'ledger', 'usage'], array_keys(self::usedPackages($directory)));
        } finally {
            unlink($directory.'/Probe.php');
            rmdir($directory);
        }
    }

    /** @return array<string, list<string>> module => declared Billium packages other than contracts and core */
    private static function modules(): array
    {
        $modules = [];
        foreach (glob(dirname(__DIR__, 2).'/modules/*/composer.json') ?: [] as $manifest) {
            $composer = json_decode((string) file_get_contents($manifest), true, 16, JSON_THROW_ON_ERROR);
            self::assertIsArray($composer);
            $require = $composer['require'] ?? [];
            self::assertIsArray($require);
            $declared = [];
            foreach (array_keys($require) as $name) {
                if (is_string($name) && preg_match('~^axiomasoft/billium-([a-z]+)$~', $name, $match) === 1) {
                    $declared[] = $match[1];
                }
            }
            $modules[basename(dirname($manifest))] = array_values(array_diff($declared, ['contracts', 'core']));
        }
        self::assertNotEmpty($modules);

        return $modules;
    }

    /** @return array<string, string> package => first file naming it */
    private static function usedPackages(string $directory): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $used = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $tree = new NodeTraverser(new NameResolver)->traverse($parser->parse((string) file_get_contents($file->getPathname())) ?? []);
            foreach ((new NodeFinder)->findInstanceOf($tree, Name::class) as $name) {
                if (preg_match('~^Billium\\\\([A-Za-z]+)\\\\~', $name->toString(), $match) === 1
                    && ! in_array($match[1], ['Contracts', 'Core'], true)) {
                    $used[strtolower($match[1])] ??= $file->getFilename();
                }
            }
        }

        return $used;
    }
}
