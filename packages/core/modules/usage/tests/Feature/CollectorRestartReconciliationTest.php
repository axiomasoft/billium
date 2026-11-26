<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\UsageFactV2;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\TestEnvironment;
use Billium\Usage\Tests\AggregationTestCase;
use Billium\Usage\Tests\Support\CollectorRestartScenario;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class CollectorRestartReconciliationTest extends AggregationTestCase
{
    public function test_exhausted_host_retries_restart_from_retained_sequences_and_reconcile_exactly_once(): void
    {
        $this->policy();
        $directory = sys_get_temp_dir().'/billium-collector-restart-'.bin2hex(random_bytes(12));
        self::assertTrue(mkdir($directory, 0700));
        $path = $directory.'/manifest.json';
        // These are explicit HOST FIXTURE inputs, not Billium defaults or external qualification.
        $arguments = [
            'statePath' => $path, 'lease' => TestEnvironment::suiteLeaseEnvironment()['BILLIUM_TEST_SUITE_LEASE'],
            'sourcePolicyRef' => 'fixture://collector/retry-3-retain-600-seconds/1',
            'repairRef' => 'repair://collector/retry-3-retain-600-seconds-1/sequences/41-42',
            'retryBudget' => 3, 'retentionMicros' => 600_000_000,
            'nowMicros' => self::micros('2026-11-01T00:05:00Z'),
        ];
        try {
            $failed = ConcurrencyStand::run(
                scenario: CollectorRestartScenario::class, workers: 1,
                args: [...$arguments, 'phase' => 'exhaust'], timeoutSeconds: 60,
            );
            self::assertArrayHasKey('ok', $failed[0], json_encode($failed, JSON_THROW_ON_ERROR));
            $retained = CollectorRestartScenario::state($path);
            self::assertSame($failed[0]['ok'] ?? self::fail('Collector exhaustion process failed'), $retained);
            self::assertSame($arguments['sourcePolicyRef'], $retained['sourcePolicyRef']);
            self::assertSame($arguments['repairRef'], $retained['repairRef']);
            self::assertSame(3, $retained['retryBudget']);
            self::assertSame([1, 2, 3], array_column($retained['failedAttempts'], 'attempt'));
            self::assertSame([41, 41, 41], array_column($retained['failedAttempts'], 'sequence'));
            self::assertSame(array_fill(0, 3, ProblemCode::ValidationInvalid->value), array_column($retained['failedAttempts'], 'error'));
            self::assertSame([41, 42], array_column($retained['buffer'], 'sequence'));
            self::assertSame(40, $retained['acknowledgedSequence']);
            self::assertSame(43, $retained['nextSequence']);
            self::assertSame([], $retained['deliveries']);
            self::assertSame(600_000_000, $retained['retainedUntilMicros'] - $retained['bufferedAtMicros']);
            self::assertFalse($retained['repaired']);
            self::assertCount(1, $retained['processes']);
            self::assertNotSame(getmypid(), $retained['processes'][0]);

            $status = $this->queries()->windowStatus($this->window());
            self::assertSame('held', $status['state']);
            self::assertSame([$arguments['repairRef']], $status['repairOrReplayRefs']);
            self::assertArrayNotHasKey('quantity', $status);
            $this->domainRefusal(fn (): array => $this->freeze(), 'window_held');
            self::assertSame(0, $this->rows('billium_usage_fact'));
            self::assertSame(0, $this->rows('billium_usage_aggregate_snapshot'));
            self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind='usage.fact_submit'"));
            self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind='usage.aggregate.freeze.v1'"));
            self::assertSame(0, $this->rows('billium_obligation'));
            self::assertSame(0, $this->rows('billium_money_commit_log'));

            // First process has exited. A new PHP process must rehydrate the durable manifest.
            $restarted = ConcurrencyStand::run(
                scenario: CollectorRestartScenario::class, workers: 1,
                args: [...$arguments, 'phase' => 'reconcile', 'nowMicros' => $arguments['nowMicros'] + 30_000_000],
                timeoutSeconds: 60,
            );
            self::assertArrayHasKey('ok', $restarted[0], json_encode($restarted, JSON_THROW_ON_ERROR));
            $repaired = CollectorRestartScenario::state($path);
            self::assertSame($restarted[0]['ok'] ?? self::fail('Collector reconciliation process failed'), $repaired);
            self::assertCount(2, array_unique($repaired['processes']));
            self::assertSame($retained['processes'][0], $repaired['processes'][0]);
            self::assertNotSame(getmypid(), $repaired['processes'][1]);
            self::assertSame($retained['buffer'], $repaired['buffer']);
            self::assertSame($retained['failedAttempts'], $repaired['failedAttempts']);
            self::assertSame($retained['sourcePolicyRef'], $repaired['sourcePolicyRef']);
            self::assertSame($retained['repairRef'], $repaired['repairRef']);
            self::assertTrue($repaired['repaired']);
            self::assertSame(42, $repaired['acknowledgedSequence']);
            self::assertSame(43, $repaired['nextSequence']);
            self::assertSame(3, $repaired['evidenceSequence']);
            self::assertSame([41, 41, 42, 42], array_column($repaired['deliveries'], 'sequence'));
            self::assertSame($repaired['deliveries'][0], $repaired['deliveries'][1]);
            self::assertSame($repaired['deliveries'][2], $repaired['deliveries'][3]);
            self::assertCount(2, array_unique(array_column($repaired['deliveries'], 'receipt')));
            self::assertSame(2, $this->rows('billium_usage_fact'));
            self::assertSame(2, $this->rows('billium_idempotency_receipt', "command_kind='usage.fact_submit'"));
            self::assertSame('qualified', $this->queries()->windowStatus($this->window())['state']);
            self::assertSame([], $this->queries()->windowStatus($this->window())['repairOrReplayRefs']);

            $frozen = $this->freeze();
            self::assertSame('12.000000001', $frozen['quantity']);
            self::assertSame($frozen['snapshotDigest'], $this->freeze()['snapshotDigest']);
            $snapshot = $this->queries()->snapshot(self::text($frozen['snapshotRef']));
            $members = self::arr($snapshot['effectiveRevisions']);
            self::assertCount(2, $members);
            $expectedDigests = array_map(static function (array $buffered): string {
                $fact = (new Codec)->decode($buffered['payload']);
                assert($fact instanceof UsageFactV2);

                return $fact->digest;
            }, $retained['buffer']);
            self::assertEqualsCanonicalizing($expectedDigests, array_column($members, 'factDigest'));
            $frontier = self::arr($snapshot['completenessFrontier']);
            self::assertCount(3, $frontier);
            self::assertSame([1, 2, 3], array_column($frontier, 'evidenceSeq'));
            self::assertSame(['dropped', 'dropped', 'complete'], array_column($frontier, 'status'));
            $evidence = self::arr($frontier[2]);
            self::assertSame(3, $evidence['evidenceSeq']);
            self::assertSame('complete', $evidence['status']);
            self::assertSame($arguments['repairRef'], $evidence['repairOrReplayRef']);
            self::assertSame(1, $this->rows('billium_usage_source_completeness', "evidence_seq=2 AND status='dropped'"));
            self::assertSame(1, $this->rows('billium_usage_aggregate_snapshot'));
            self::assertSame(2, $this->rows('billium_usage_aggregate_member'));
            self::assertSame('12.000000001', $this->queries()->windowStatus($this->window())['quantity']);
            self::assertSame(0, $this->rows('billium_obligation'));
            self::assertSame(0, $this->rows('billium_money_commit_log'));
        } finally {
            foreach ([$path, $path.'.tmp'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }
}
