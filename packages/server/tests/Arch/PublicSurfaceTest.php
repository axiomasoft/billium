<?php

declare(strict_types=1);

namespace Billium\Server\Tests\Arch;

use Billium\Server\Contracts\Authenticator;
use Billium\Server\Contracts\AuthorizationVerdict;
use Billium\Server\Contracts\SellerConfiguration;
use Billium\Server\Contracts\SellerContextResolver;
use Billium\Server\Contracts\SellerEligibility;
use Billium\Server\Contracts\UsageAttributionResolver;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use PhpParser\Node\Expr\Cast\Double;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

#[Group('server')]
final class PublicSurfaceTest extends TestCase
{
    public function test_host_ports_are_implement_spis_and_data_is_immutable(): void
    {
        foreach ([Authenticator::class, SellerContextResolver::class, AuthorizationVerdict::class,
            SellerEligibility::class, SellerConfiguration::class, UsageAttributionResolver::class] as $contract) {
            $attributes = (new ReflectionClass($contract))->getAttributes(Spi::class);
            self::assertCount(1, $attributes, $contract);
            self::assertSame(SpiKind::Implement, $attributes[0]->newInstance()->kind, $contract);
        }
        foreach (glob(dirname(__DIR__, 2).'/src/Data/*.php') ?: [] as $path) {
            $type = 'Billium\\Server\\Data\\'.basename($path, '.php');
            self::assertTrue((new ReflectionClass($type))->isReadOnly(), $type);
        }
    }

    public function test_server_has_no_eloquent_float_or_host_coupling(): void
    {
        $base = dirname(__DIR__, 2).'/src';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $public = str_starts_with($path, $base.'/Contracts/') || str_starts_with($path, $base.'/Data/');
            $php = file_get_contents($path);
            self::assertIsString($php);
            self::assertSame([], self::violations($php, $public), $path);
        }
    }

    public function test_guard_detects_eloquent_float_and_host_dependencies(): void
    {
        self::assertSame([
            'Illuminate\\Database\\Eloquent\\Model', 'HostApp\\Identity\\Account', 'Illuminate\\Http\\Request',
            'float type', 'float cast', 'float literal',
        ], self::violations(<<<'PHP'
            <?php
            use Illuminate\Database\Eloquent\Model as Record;
            use HostApp\Identity\Account;
            use Illuminate\Http\Request;
            function bad(Record $record): float { return (float) 0.25; }
            PHP, true));
    }

    /** @return list<string> */
    private static function violations(string $php, bool $public): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($php) ?? [];
        $violations = [];
        foreach ((new NodeFinder)->find($nodes, static fn ($node): bool => $node instanceof Name || $node instanceof Float_ || $node instanceof Double
            || ($node instanceof Identifier && strtolower($node->name) === 'float'),
        ) as $node) {
            if ($node instanceof Name) {
                $name = $node->toString();
                if (str_starts_with(strtolower($name), 'illuminate\\database\\eloquent')
                    || (str_contains($name, '\\')
                        && preg_match('/^(?:Billium\\\\(?:Contracts|Core|Execution|Ledger|Server)|Packman|Illuminate)(?:\\\\|$)/i', $name) !== 1)
                    || ($public && str_starts_with(strtolower($name), 'illuminate\\'))) {
                    $violations[] = $name;
                }

                continue;
            }
            $violations[] = match (true) {
                $node instanceof Float_ => 'float literal',
                $node instanceof Double => 'float cast',
                default => 'float type',
            };
        }

        return $violations;
    }
}
