<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Arch;

use PHPUnit\Framework\TestCase;

final class TableOwnershipTest extends TestCase
{
    public function test_table_literals_stay_inside_the_migration_owners_internal_directory(): void
    {
        $root = dirname(__DIR__, 2);
        $ownership = MigrationTables::ownership($root.'/database/migrations');
        $violations = [];
        foreach ([$root.'/src', $root.'/modules', $root.'/config'] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = substr($file->getPathname(), strlen($root) + 1);
                if (str_contains($path, '/tests/')) {
                    continue;
                }
                $violations = array_merge($violations, self::violations((string) file_get_contents($file->getPathname()), $path, $ownership));
            }
        }
        self::assertSame([], $violations, 'D45/INV-01: table access belongs to its migration owner');
    }

    public function test_ownership_is_derived_from_create_and_rejects_cross_module_literals(): void
    {
        $source = <<<'PHP'
            <?php
            // Schema::create('billium_not_created', function ($table) {});
            Schema::create('billium_payment', function ($table) {});
            DB::unprepared('CREATE TABLE public.billium_allocation (id bigint REFERENCES billium_obligation(id))');
            PHP;
        self::assertSame(['billium_payment', 'billium_allocation'], MigrationTables::createdBy($source));
        $owners = array_fill_keys(MigrationTables::createdBy($source), 'ledger');
        self::assertNotEmpty(self::violations("<?php DB::table('billium_payment');", 'modules/platform/src/Internal/Bad.php', $owners));
        self::assertNotEmpty(self::violations("<?php DB::select('SELECT * FROM billium_payment');", 'src/Internal/Bad.php', $owners));
        self::assertNotEmpty(self::violations("<?php const TABLE = 'billium_payment';", 'modules/ledger/src/Data/Bad.php', $owners));
        self::assertNotEmpty(self::violations("<?php return ['table' => 'billium_payment'];", 'config/billium.php', $owners));
        self::assertSame([], self::violations("<?php DB::table('billium_payment');", 'modules/ledger/src/Internal/Good.php', $owners));
        self::assertSame([], self::violations('<?php // billium_payment is only a comment', 'modules/platform/src/Internal/Good.php', $owners));
    }

    /**
     * @param  array<string, string>  $ownership
     * @return list<string>
     */
    private static function violations(string $source, string $path, array $ownership): array
    {
        $violations = [];
        foreach (token_get_all($source) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            preg_match_all('/\bbillium_[a-z0-9_]+\b/', $token[1], $tables);
            foreach ($tables[0] as $table) {
                if (isset($ownership[$table]) && ! str_starts_with($path, 'modules/'.$ownership[$table].'/src/Internal/')) {
                    $violations[] = $path.': '.$table.' belongs to '.$ownership[$table];
                }
            }
        }

        return $violations;
    }
}
