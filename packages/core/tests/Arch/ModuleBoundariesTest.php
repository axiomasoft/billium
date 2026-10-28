<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Arch;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Core\BilliumCoreServiceProvider;
use Billium\Ledger\Internal\LedgerServiceProvider;
use Billium\Platform\Internal\PlatformServiceProvider;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionClass;

final class ModuleBoundariesTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BilliumCoreServiceProvider::class];
    }

    public function test_composition_registers_modules_config_and_migration_paths(): void
    {
        self::assertNotEmpty($this->app->getProviders(PlatformServiceProvider::class));
        self::assertNotEmpty($this->app->getProviders(LedgerServiceProvider::class));
        self::assertSame(3, config('billium.runner.max_attempts'));
        self::assertNull(config('billium.outbox.retention_floor_seconds'));
        self::assertSame(100, config('billium.feed.drain_batch'));
        self::assertTrue(config('billium.runtime_role.preflight'));
        $paths = array_map(realpath(...), $this->app['migrator']->paths());
        foreach (['platform', 'ledger'] as $module) {
            self::assertContains(realpath(dirname(__DIR__, 2).'/database/migrations/'.$module), $paths);
        }
    }

    public function test_modules_keep_internal_dependencies_and_database_access_private(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(self::phpFiles($root.'/src'), self::phpFiles($root.'/modules'));
        self::assertNotEmpty($files);
        $violations = [];
        foreach ($files as $file) {
            $path = substr($file, strlen($root) + 1);
            if (str_contains($path, '/tests/')) {
                continue;
            }
            $source = (string) file_get_contents($file);
            $violations = array_merge($violations, self::violations($source, $path));
            if (preg_match('~^modules/([^/]+)/src/([^/]+)/(.+)\.php$~', $path, $match) === 1 && $match[2] !== 'Internal') {
                $class = 'Billium\\'.ucfirst($match[1]).'\\'.$match[2].'\\'.str_replace('/', '\\', $match[3]);
                $reflection = new ReflectionClass($class);
                if ($reflection->getAttributes(Api::class) === [] && $reflection->getAttributes(Spi::class) === []) {
                    $violations[] = $path.': public type needs #[Api] or #[Spi] (M-38)';
                }
            }
        }
        self::assertSame([], $violations);
    }

    public function test_boundary_checker_rejects_foreign_internal_and_public_database_access(): void
    {
        self::assertNotEmpty(self::violations('<?php use Billium\\Ledger\\Internal\\Secret;', 'modules/platform/src/Internal/Bad.php'));
        self::assertNotEmpty(self::violations('<?php use Billium\\Ledger as Other; new Other\\Internal\\Secret;', 'modules/platform/src/Internal/Bad.php'));
        self::assertNotEmpty(self::violations('<?php new \\Billium\\Core\\Internal\\Runner\\LockSet;', 'modules/ledger/src/Internal/Bad.php'));
        self::assertNotEmpty(self::violations('<?php use Illuminate\\Support\\Facades\\DB;', 'modules/ledger/src/Contracts/Bad.php'));
        self::assertNotEmpty(self::violations('<?php use Illuminate\\Database\\Eloquent\\Model;', 'modules/ledger/src/Internal/Bad.php'));
        self::assertNotEmpty(self::violations('<?php $query->lockForUpdate();', 'modules/ledger/src/Internal/Bad.php'));
        self::assertNotEmpty(self::violations('<?php class Bad {}', 'modules/ledger/src/Services/Bad.php'));
        self::assertSame([], self::violations('<?php use Billium\\Ledger\\Contracts\\Ledger;', 'modules/platform/src/Internal/Good.php'));
    }

    public function test_database_tests_never_use_transaction_wrappers(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (array_merge(self::phpFiles($root.'/tests'), self::phpFiles($root.'/modules')) as $file) {
            if (str_ends_with($file, '/DatabaseTestCase.php')) {
                continue; // D50: the base class names these traits only to reject them.
            }
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token) && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
                    self::assertDoesNotMatchRegularExpression('/(?:^|\\\\)(?:RefreshDatabase|DatabaseTransactions)$/', $token[1], $file);
                }
            }
        }
    }

    /** @return list<string> */
    private static function violations(string $source, string $path): array
    {
        $violations = [];
        preg_match('~^modules/([^/]+)/src/([^/]+)~', $path, $location);
        $module = $location[1] ?? 'core';
        $category = $location[2] ?? 'Internal';
        if (! in_array($category, ['Internal', 'Contracts', 'Data', 'Enums', 'Events', 'Exceptions'], true)) {
            $violations[] = $path.': unsupported public category (M-38)';
        }
        $tree = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $tree = new NodeTraverser(new NameResolver)->traverse($tree);
        $references = array_map(
            static fn (Name $name): array => [T_NAME_FULLY_QUALIFIED, $name->toString()],
            (new NodeFinder)->findInstanceOf($tree, Name::class),
        );
        foreach (array_merge(token_get_all($source), $references) as $token) {
            if (! is_array($token)) {
                continue;
            }
            [$type, $value] = $token;
            if (in_array($type, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name = ltrim($value, '\\');
                if (preg_match('/^Billium\\\\(Platform|Ledger|Core)\\\\Internal(?:\\\\|$)/', $name, $dependency) === 1
                    && strtolower($dependency[1]) !== $module) {
                    $provider = 'Billium\\'.$dependency[1].'\\Internal\\'.$dependency[1].'ServiceProvider';
                    if ($path !== 'src/BilliumCoreServiceProvider.php' || $name !== $provider) {
                        $violations[] = $path.': foreign Internal dependency '.$name.' (D55/INV-01)';
                    }
                }
                if (str_starts_with($name, 'Illuminate\\Database\\Eloquent')
                    || ($category !== 'Internal' && (str_starts_with($name, 'Illuminate\\Database\\') || $name === DB::class))) {
                    $violations[] = $path.': database dependency '.$name.' (M-38)';
                }
            }
            if ($path !== 'src/Internal/Runner/LockSet.php'
                && (($type === T_STRING && in_array(strtolower($value), ['lockforupdate', 'sharedlock'], true))
                    || ($type === T_CONSTANT_ENCAPSED_STRING && preg_match('/\bFOR\s+(?:NO\s+KEY\s+UPDATE|KEY\s+SHARE|UPDATE|SHARE)\b/i', $value) === 1))) {
                $violations[] = $path.': row locks belong to LockSet (invariant-model)';
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private static function phpFiles(string $directory): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
