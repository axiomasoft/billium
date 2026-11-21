<?php

declare(strict_types=1);

namespace Billium\Server\Tests\Arch;

use Billium\Server\Tests\Support\MinimalHost;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[Group('server')]
final class ServerBoundaryTest extends TestCase
{
    public function test_server_has_only_delivery_dependencies(): void
    {
        $base = dirname(__DIR__, 2);
        $package = $this->manifest($base.'/composer.json');
        self::assertSame(['php', 'axiomasoft/billium-contracts', 'axiomasoft/billium-core', 'axiomasoft/billium-ledger', 'axiomasoft/packman', 'laravel/framework'], array_keys($package['require']));
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.'/src')) as $file) {
            if ($file->getExtension() === 'php') {
                self::assertSame([], self::forbidden(self::contents($file->getPathname())), $file->getPathname());
            }
        }
        self::assertSame([], self::forbidden(self::contents($base.'/routes/http.php')));
        self::assertFileDoesNotExist($base.'/database');

        foreach (['contracts', 'core'] as $name) {
            $dependency = $this->manifest(MinimalHost::root().'/packages/'.$name.'/composer.json');
            self::assertArrayNotHasKey('axiomasoft/billium-server', $dependency['require']);
        }
        $core = $this->manifest(MinimalHost::root().'/packages/core/composer.json');
        self::assertSame('self.version', $core['replace']['axiomasoft/billium-ledger']);
        self::assertSame('self.version', $core['replace']['axiomasoft/billium-execution']);
        self::assertArrayNotHasKey('laravel/framework', $this->manifest(MinimalHost::root().'/packages/contracts/composer.json')['require']);
    }

    public function test_import_guard_flags_aliases_and_fully_qualified_names(): void
    {
        self::assertSame([
            'AxiomaSoft\Commerz\Payments',
            'Tariffler\PlanVersion',
            'AzGuard\Verifier',
            'Stripe\StripeClient',
            'YooKassa\Client',
            'AcmePay\Sdk\Client',
            'Billium\Ledger\Internal\DirectWriter',
        ], self::forbidden(<<<'PHP'
            <?php
            use AxiomaSoft\Commerz\Payments as Gateway;
            use Tariffler\PlanVersion;
            use AzGuard\Verifier;
            new \Stripe\StripeClient;
            new \YooKassa\Client;
            new \AcmePay\Sdk\Client;
            new \Billium\Ledger\Internal\DirectWriter;
            PHP));
    }

    /** @return list<string> */
    private static function forbidden(string $php): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($php) ?? [];
        $findings = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Name::class) as $name) {
            $import = $name->toString();
            if ((str_contains($import, '\\') && preg_match('/^(?:Billium\\\\(?:Contracts|Core|Execution|Ledger|Server)|Illuminate|Packman)(?:\\\\|$)/i', $import) !== 1)
                || preg_match('/^Billium\\\\(?!Server\\\\)[^\\\\]+\\\\Internal\\\\/i', $import) === 1) {
                $findings[] = $import;
            }
        }

        return $findings;
    }

    /** @return array{require: array<string, string>, replace?: array<string, string>} */
    private function manifest(string $path): array
    {
        return json_decode(self::contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function contents(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }
}
