<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P10;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('P10.8')]
#[Group('D71')]
#[Group('D72')]
final class ReleaseCandidateTest extends TestCase
{
    public function test_local_only_rc_validation_passes_on_clean_local_profile(): void
    {
        $evidenceFile = self::writeTempJson([
            'suites' => [
                ['name' => 'p10-release-candidate', 'testsExecuted' => 4, 'failed' => 0, 'exitCode' => 0],
            ],
        ]);

        try {
            $result = self::runReleaseCandidate([
                '--local-only',
                '--profile',
                'embedded',
                '--modules',
                'platform,ledger,documents',
                '--acceptance-evidence',
                $evidenceFile,
            ]);

            self::assertSame(0, $result['exitCode'], $result['rawStdout'].$result['rawStderr']);
            self::assertSame('billium.quality.release_candidate.v1', $result['report']['schema']);
            self::assertSame('local-only', $result['report']['mode']);
            self::assertSame('passed', $result['report']['status']);
            self::assertSame(0, $result['report']['errorCount']);
            self::assertSame(0, $result['report']['sideEffects']['networkCalls']);
            self::assertFalse($result['report']['sideEffects']['publishExecuted']);
            self::assertFalse($result['report']['sideEffects']['gitMutations']);
            self::assertTrue($result['report']['checks']['profileModules']['passed']);
            self::assertTrue($result['report']['checks']['supplyChainAndHolds']['passed']);
            self::assertTrue($result['report']['checks']['mech02']['emptyFilterRejected']);
            self::assertGreaterThan(0, $result['report']['checks']['mech02']['discoveredTests']);
            self::assertTrue($result['report']['checks']['acceptanceEvidence']['passed']);
        } finally {
            @unlink($evidenceFile);
        }
    }

    public function test_rejects_zero_test_acceptance_evidence(): void
    {
        $zeroTotalFile = self::writeTempJson([
            'testsExecuted' => 0,
        ]);
        $zeroSuiteFile = self::writeTempJson([
            'suites' => [
                ['name' => 'p10-ok', 'testsExecuted' => 3, 'failed' => 0, 'exitCode' => 0],
                ['name' => 'p10-empty', 'testsExecuted' => 0, 'failed' => 0, 'exitCode' => 0],
            ],
        ]);

        try {
            $res1 = self::runReleaseCandidate([
                '--local-only',
                '--profile',
                'embedded',
                '--acceptance-evidence',
                $zeroTotalFile,
            ]);
            self::assertSame(1, $res1['exitCode']);
            self::assertSame('failed', $res1['report']['status']);
            self::assertContains('rc.zero_test_evidence', array_column($res1['report']['errors'], 'code'));

            $res2 = self::runReleaseCandidate([
                '--local-only',
                '--profile',
                'embedded',
                '--acceptance-evidence',
                $zeroSuiteFile,
            ]);
            self::assertSame(1, $res2['exitCode']);
            self::assertSame('failed', $res2['report']['status']);
            self::assertContains('rc.zero_test_evidence', array_column($res2['report']['errors'], 'code'));
        } finally {
            @unlink($zeroTotalFile);
            @unlink($zeroSuiteFile);
        }
    }

    public function test_rejects_unresolved_profile_holds_and_invalid_module_sets(): void
    {
        $holdsFile = self::writeTempJson([
            'holds' => [
                [
                    'id' => 'HOLD-RC-001',
                    'profile' => 'embedded',
                    'status' => 'unresolved',
                    'reason' => 'Pending retention floor sign-off',
                ],
            ],
        ]);

        try {
            // Case 1: explicit unresolved hold for selected profile blocks RC.
            $holdResult = self::runReleaseCandidate([
                '--local-only',
                '--profile',
                'embedded',
                '--holds-file',
                $holdsFile,
            ]);
            self::assertSame(1, $holdResult['exitCode']);
            self::assertSame('failed', $holdResult['report']['status']);
            self::assertContains('rc.unresolved_holds', array_column($holdResult['report']['errors'], 'code'));

            // Case 2: remote-live profile is blocked by unresolved activation gate holds in server-readiness.json.
            $liveResult = self::runReleaseCandidate([
                '--local-only',
                '--profile',
                'remote-live',
            ]);
            self::assertSame(1, $liveResult['exitCode']);
            self::assertSame('failed', $liveResult['report']['status']);
            self::assertContains('rc.unresolved_holds', array_column($liveResult['report']['errors'], 'code'));

            // Case 3: forbidden module in embedded profile is rejected.
            $badModules = self::runReleaseCandidate([
                '--local-only',
                '--profile',
                'embedded',
                '--modules',
                'platform,ledger,execution',
            ]);
            self::assertSame(1, $badModules['exitCode']);
            self::assertSame('failed', $badModules['report']['status']);
            self::assertContains('rc.profile_module_invalid', array_column($badModules['report']['errors'], 'code'));
        } finally {
            @unlink($holdsFile);
        }
    }

    public function test_confirms_mech02_empty_suite_enforcement(): void
    {
        $missingFilter = '--filter=__billium_mech02_intentionally_nonexistent_test__';

        // 1. Direct Pest invocation with nonexistent filter must fail with "No tests found".
        $pest = self::runProcess(['vendor/bin/pest', $missingFilter]);
        self::assertNotSame(0, $pest['exitCode'], 'Requires nonexistent Pest filter to return nonzero');
        self::assertStringContainsString('No tests found', $pest['stdout'].$pest['stderr']);

        // 2. Discovery gate script rejects empty selection.
        $discovery = self::runProcess(['python3', 'scripts/lint/test-discovery.py', $missingFilter]);
        self::assertNotSame(0, $discovery['exitCode'], 'test-discovery.py must reject empty selection');
        self::assertStringContainsString('Empty Pest selection', $discovery['stdout'].$discovery['stderr']);
    }

    /**
     * @param  list<string>  $args
     * @return array{exitCode: int, report: array<string, mixed>, rawStdout: string, rawStderr: string}
     */
    private static function runReleaseCandidate(array $args): array
    {
        $cmd = array_merge(['bash', self::repoPath('scripts/quality/release-candidate.sh')], $args);
        $proc = self::runProcess($cmd);
        $decoded = json_decode($proc['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return [
            'exitCode' => $proc['exitCode'],
            'report' => $decoded,
            'rawStdout' => $proc['stdout'],
            'rawStderr' => $proc['stderr'],
        ];
    }

    /**
     * @param  list<string>  $cmd
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private static function runProcess(array $cmd): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, self::repoRoot());
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        return [
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function writeTempJson(array $payload): string
    {
        $path = tempnam(sys_get_temp_dir(), 'billium-rc-test-');
        self::assertIsString($path);
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $path;
    }

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    private static function repoPath(string $relative): string
    {
        return self::repoRoot().DIRECTORY_SEPARATOR.$relative;
    }
}
