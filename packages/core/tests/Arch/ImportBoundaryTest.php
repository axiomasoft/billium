<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Arch;

use Billium\Contracts\Contracts\Codec;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('P9.6')]
#[Group('D71')]
#[Group('IM-17')]
final class ImportBoundaryTest extends TestCase
{
    public function test_im17_p9_surfaces_contain_no_external_product_names_or_legacy_contracts(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $targets = self::collectP9Files($repoRoot);
        self::assertNotEmpty($targets);

        $forbiddenTerms = self::forbiddenTerms();
        $violations = [];

        foreach ($targets as $file) {
            $rel = substr($file, strlen($repoRoot) + 1);
            $content = (string) file_get_contents($file);
            $lower = strtolower($content);

            foreach ($forbiddenTerms as $term => $reason) {
                if (str_contains($lower, strtolower($term))) {
                    $violations[] = "{$rel}: matched forbidden token ({$reason})";
                }
            }
        }

        self::assertSame([], $violations, 'IM-17 / D71: P9 surfaces must remain strictly product-agnostic and legacy-free');
    }

    public function test_im17_p9_production_code_touches_only_billium_owned_tables_and_never_accesses_external_product_databases(): void
    {
        $coreRoot = dirname(__DIR__, 2);
        $repoRoot = dirname(__DIR__, 4);
        $ownership = MigrationTables::ownership($coreRoot.'/database/migrations');
        self::assertArrayHasKey('billium_import_batch', $ownership);
        self::assertArrayHasKey('billium_import_record', $ownership);
        self::assertArrayHasKey('billium_import_mapping', $ownership);
        self::assertArrayHasKey('billium_import_receipt', $ownership);

        $productionDirs = [
            $coreRoot.'/modules/platform/src/Contracts/Import',
            $coreRoot.'/modules/platform/src/Data/Import',
            $coreRoot.'/modules/platform/src/Internal/Import',
            $coreRoot.'/modules/platform/src/Contracts/Migration',
            $coreRoot.'/modules/platform/src/Data/Migration',
            $coreRoot.'/modules/platform/src/Internal/Migration',
            $coreRoot.'/modules/ledger/src/Internal/Import',
        ];

        $phpFiles = [];
        foreach ($productionDirs as $dir) {
            if (is_dir($dir)) {
                foreach (self::filesWithExtensions($dir, ['php']) as $file) {
                    $phpFiles[] = $file;
                }
            }
        }

        self::assertNotEmpty($phpFiles);
        $violations = [];
        foreach ($phpFiles as $file) {
            $relCore = substr($file, strlen($coreRoot) + 1);
            $source = (string) file_get_contents($file);
            $violations = array_merge($violations, self::scanSqlBoundaryViolations($source, $relCore, $ownership));
        }

        self::assertSame([], $violations, 'IM-17: P9 production code must only access owned Billium tables and never external host DBs');

        // Negative control assertions proving the scanner rejects foreign product tables and external DB connections.
        self::assertNotEmpty(self::scanSqlBoundaryViolations(
            '<?php $db->select("SELECT * FROM external_orders WHERE id = 1");',
            'modules/platform/src/Internal/Import/Bad.php',
            $ownership,
        ));
        self::assertNotEmpty(self::scanSqlBoundaryViolations(
            '<?php new PDO("pgsql:host=remote;dbname=external");',
            'modules/platform/src/Internal/Import/Bad.php',
            $ownership,
        ));
        self::assertNotEmpty(self::scanSqlBoundaryViolations(
            '<?php $db->table("billium_payment")->get();',
            'modules/platform/src/Internal/Import/Bad.php',
            $ownership,
        ));
        self::assertSame([], self::scanSqlBoundaryViolations(
            '<?php $db->select("SELECT * FROM public.billium_import_batch WHERE batch_id = ?");',
            'modules/platform/src/Internal/Import/Good.php',
            $ownership,
        ));

        // Also check CLI script for forbidden external DB drivers.
        $cliFile = $repoRoot.'/scripts/import/billium-import.php';
        self::assertFileExists($cliFile);
        $cliSource = (string) file_get_contents($cliFile);
        self::assertDoesNotMatchRegularExpression('/\b(?:mysqli_connect|pg_connect|sqlite_open)\s*\(/i', $cliSource);
    }

    public function test_im17_import_fixtures_and_golden_corpus_enforce_sanitization_checksums_and_no_sensitive_payload(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $importFixtureRoot = $repoRoot.'/fixtures/integration/import';
        self::assertDirectoryExists($importFixtureRoot);

        $codec = new Codec;
        $jsonFiles = self::filesWithExtensions($importFixtureRoot, ['json']);
        self::assertNotEmpty($jsonFiles);

        $sensitivePatterns = [
            '/-----BEGIN (?:RSA |EC |OPENSSH |)PRIVATE KEY-----/',
            '/\b(?:sk_live|rk_live|pk_live|whsec)_[A-Za-z0-9]{8,}\b/',
            '/"(?:card_number|cvv|cvc|raw_pan|taxpayer_ssn|passport_number)"\s*:/i',
        ];

        $exportArtifactsChecked = 0;
        foreach ($jsonFiles as $file) {
            $base = basename($file);
            $raw = (string) file_get_contents($file);
            $doc = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($doc, $file);

            if (str_starts_with($base, 'negative-')) {
                continue;
            }

            foreach ($sensitivePatterns as $pattern) {
                self::assertDoesNotMatchRegularExpression($pattern, $raw, "Sensitive payload pattern found in {$file}");
            }

            if (($doc['exportSchema'] ?? null) === 'source.export.v1') {
                $exportArtifactsChecked++;
                self::assertTrue($doc['sanitizationPolicy']['piiRedacted'] ?? false, "{$base}: piiRedacted must be true");
                self::assertTrue($doc['sanitizationPolicy']['secretsStripped'] ?? false, "{$base}: secretsStripped must be true");
                self::assertIsArray($doc['records'] ?? null, "{$base}: records must be a list");
                self::assertSame(count($doc['records']), $doc['recordCount'] ?? -1, "{$base}: recordCount mismatch");

                $expectedRecordsChecksum = 'sha256:'.hash('sha256', $codec->canonicalize($doc['records']));
                self::assertSame($expectedRecordsChecksum, $doc['recordsChecksum'] ?? null, "{$base}: recordsChecksum mismatch");

                if (isset($doc['artifactDigest'])) {
                    $withoutDigest = $doc;
                    unset($withoutDigest['artifactDigest']);
                    $expectedArtifactDigest = 'sha256:'.hash('sha256', $codec->canonicalize($withoutDigest));
                    self::assertSame($expectedArtifactDigest, $doc['artifactDigest'], "{$base}: artifactDigest mismatch");
                }
            }
        }

        self::assertGreaterThanOrEqual(4, $exportArtifactsChecked);
    }

    /**
     * @param  array<string, string>  $ownership
     * @return list<string>
     */
    private static function scanSqlBoundaryViolations(string $source, string $path, array $ownership): array
    {
        $violations = [];
        $externalDbScheme = 'my'.'sql:';

        if (preg_match('/\b(?:new\s+\\\\?PDO|mysqli_connect|pg_connect)\s*\(/i', $source) === 1
            || stripos($source, $externalDbScheme) !== false
        ) {
            $violations[] = "{$path}: direct external database driver/DSN connection is forbidden";
        }

        foreach (token_get_all($source) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            $literal = $token[1];

            preg_match_all('/\bbillium_[a-z0-9_]+\b/', $literal, $billiumMatches);
            foreach ($billiumMatches[0] as $table) {
                if (isset($ownership[$table]) && ! str_starts_with($path, 'modules/'.$ownership[$table].'/src/Internal/')) {
                    $violations[] = "{$path}: {$table} belongs to {$ownership[$table]}";
                }
            }

            $looksLikeSql = preg_match('/\b(?:SELECT\s+.+\s+FROM|INSERT\s+INTO|UPDATE\s+(?:public\.)?[a-z_]+\s+SET|DELETE\s+FROM|CREATE\s+TABLE|ALTER\s+TABLE)\b/is', $literal) === 1;
            if ($looksLikeSql && preg_match_all('/\b(?:FROM|INTO|UPDATE|JOIN)\s+(?:public\.)?([a-z_][a-z0-9_]{2,})\b/i', $literal, $sqlMatches) > 0) {
                foreach ($sqlMatches[1] as $sqlTable) {
                    $lowerTable = strtolower($sqlTable);
                    if (in_array($lowerTable, ['stdin', 'stdout', 'jsonb_to_recordset', 'pg_locks', 'pg_database', 'information_schema'], true)) {
                        continue;
                    }
                    if (! str_starts_with($lowerTable, 'billium_')) {
                        $violations[] = "{$path}: references non-Billium SQL table '{$sqlTable}'";
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * @return array<string, string>
     */
    private static function forbiddenTerms(): array
    {
        return [
            'mem'.'ster' => 'D71 forbids external product name',
            'edu'.'fy' => 'D71 forbids external product name',
            'com'.'merz' => 'D71 forbids external product name',
            'leg'.'acy.import.v1' => 'D71 forbids legacy contract name',
            'leg'.'acy_unanchored' => 'D71 forbids legacy unanchored class name',
            'Leg'.'acyScenarioTest' => 'D71 forbids legacy test class name',
        ];
    }

    /**
     * @return list<string>
     */
    private static function collectP9Files(string $repoRoot): array
    {
        $dirs = [
            $repoRoot.'/packages/core/modules/platform/src/Contracts/Import',
            $repoRoot.'/packages/core/modules/platform/src/Data/Import',
            $repoRoot.'/packages/core/modules/platform/src/Internal/Import',
            $repoRoot.'/packages/core/modules/platform/src/Contracts/Migration',
            $repoRoot.'/packages/core/modules/platform/src/Data/Migration',
            $repoRoot.'/packages/core/modules/platform/src/Internal/Migration',
            $repoRoot.'/packages/core/modules/platform/tests/Import',
            $repoRoot.'/packages/core/modules/platform/tests/Migration',
            $repoRoot.'/packages/core/modules/ledger/src/Internal/Import',
            $repoRoot.'/spec/revisions/2026-10/schema/import',
            $repoRoot.'/spec/revisions/2026-10/schema/migration',
            $repoRoot.'/fixtures/integration/import',
            $repoRoot.'/fixtures/integration/consumer',
            $repoRoot.'/fixtures/vectors/import',
            $repoRoot.'/conformance/import',
            $repoRoot.'/conformance/consumers/bridge',
            $repoRoot.'/docs/migrations',
        ];

        $files = [];
        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                foreach (self::filesWithExtensions($dir, ['php', 'json', 'py', 'sh', 'md']) as $file) {
                    $files[] = $file;
                }
            }
        }

        foreach ([
            $repoRoot.'/packages/core/tests/Arch/ImportBoundaryTest.php',
            $repoRoot.'/scripts/import/billium-import.php',
            $repoRoot.'/scripts/spec/check-import-contract.py',
            $repoRoot.'/scripts/spec/check-import-fixtures.py',
            $repoRoot.'/docs/contracts/external-import-v1.md',
            $repoRoot.'/docs/contracts/consumer-contract-kit.md',
        ] as $single) {
            if (is_file($single)) {
                $files[] = $single;
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /**
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private static function filesWithExtensions(string $directory, array $extensions): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && in_array($file->getExtension(), $extensions, true)) {
                if (str_contains($file->getPathname(), '/__pycache__/') || str_contains($file->getPathname(), '/ct-')) {
                    continue;
                }
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
