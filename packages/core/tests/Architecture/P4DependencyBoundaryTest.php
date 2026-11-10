<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Architecture;

use Billium\Core\Tests\Arch\MigrationTables;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class P4DependencyBoundaryTest extends TestCase
{
    public function test_commercial_modules_have_no_neighbour_renderer_or_foreign_table_access(): void
    {
        $root = dirname(__DIR__, 2);
        $owners = MigrationTables::ownership($root.'/database/migrations');
        $violations = [];
        foreach (['usage', 'rating', 'schedule'] as $module) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/modules/'.$module.'/src')) as $file) {
                assert($file instanceof SplFileInfo);
                if ($file->getExtension() === 'php') {
                    $source = file_get_contents($file->getPathname());
                    self::assertIsString($source);
                    $violations = [...$violations, ...self::violations(source: $source, module: $module, owners: $owners)];
                }
            }
        }
        self::assertSame([], $violations);
    }

    public function test_checker_catches_aliases_foreign_writes_sql_and_forced_renderers(): void
    {
        $owners = ['billium_obligation' => 'ledger', 'billium_rating_run' => 'rating'];
        foreach ([
            '<?php use Tariffler\\Contracts as T; new T\\Grants;',
            '<?php new \\OpenMeter\\Client;',
            '<?php use Billium\\Documents as D; new D\\Contracts\\Renderer;',
            '<?php $db->table("billium_obligation")->insert(["amount" => 1]);',
            '<?php $db->statement("UPDATE public.billium_obligation SET amount=1");',
            '<?php $db->table("vendor_invoices")->delete();',
            '<?php $db->statement("SELECT * FROM vendor_invoices");',
            '<?php $db->table($unverifiedTable)->insert([]);',
        ] as $bad) {
            self::assertNotEmpty(self::violations(source: $bad, module: 'rating', owners: $owners), $bad);
        }
        self::assertSame([], self::violations(source: '<?php use Billium\\Ledger\\Contracts\\LedgerParticipants; $db->table("billium_rating_run")->insert([]);', module: 'rating', owners: $owners));
        self::assertSame([], self::violations(source: '<?php // UPDATE public.billium_obligation SET amount=1', module: 'rating', owners: $owners));
        self::assertSame([], self::violations(source: '<?php throw new Exception("seller differs from authenticated caller");', module: 'rating', owners: $owners));
    }

    /** @param array<string, string> $owners
     * @return list<string>
     */
    private static function violations(string $source, string $module, array $owners): array
    {
        $tree = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $tree = new NodeTraverser(new NameResolver)->traverse($tree);
        $finder = new NodeFinder;
        $violations = [];
        foreach ($finder->findInstanceOf($tree, Name::class) as $name) {
            if (preg_match('~^(?:Commerz|Tariffler|CoreX|Lago|OpenMeter|Stripe|AiFina|AzGuard|Vaulter|Billium\\\\(?:Documents|Execution|Collection|Server))(?:\\\\|$)~i', $name->toString()) === 1) {
                $violations[] = $module.': forbidden dependency '.$name->toString();
            }
        }
        foreach ($finder->findInstanceOf($tree, String_::class) as $literal) {
            preg_match_all('/\bbillium_[a-z0-9_]+\b/', $literal->value, $tables);
            foreach ($tables[0] as $table) {
                if (isset($owners[$table]) && $owners[$table] !== $module) {
                    $violations[] = $module.': foreign table '.$table;
                }
            }
            if (preg_match('/^\s*(?:SELECT|INSERT|UPDATE|DELETE|TRUNCATE|WITH)\b/i', $literal->value) !== 1) {
                continue;
            }
            preg_match_all('/\b(?:FROM|JOIN|UPDATE|INTO|TRUNCATE(?:\s+TABLE)?)\s+(?:public\.)?([a-z_][a-z0-9_]*)/i', $literal->value, $sql);
            foreach ($sql[1] as $table) {
                if (! str_starts_with($table, 'billium_'.$module.'_')) {
                    $violations[] = $module.': foreign SQL table '.$table;
                }
            }
        }
        foreach ($finder->findInstanceOf($tree, MethodCall::class) as $call) {
            if (! $call->name instanceof Identifier || $call->name->toString() !== 'table') {
                continue;
            }
            $argument = $call->getArgs()[0]->value ?? null;
            $prefix = $argument instanceof Concat ? $argument->left : $argument;
            if (! $prefix instanceof String_ || ! str_starts_with($prefix->value, 'billium_'.$module.'_')) {
                $violations[] = $module.': table access without an owned prefix';
            }
        }

        return array_values(array_unique($violations));
    }
}
