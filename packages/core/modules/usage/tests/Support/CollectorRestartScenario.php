<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Support;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\UsageFactV2;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\TestEnvironment;
use Billium\Usage\Contracts\SourceCompletenessIntake;
use Billium\Usage\Contracts\Usage;
use Billium\Usage\Contracts\UsageAttribution;
use Billium\Usage\Data\Commands\RecordSourceCompleteness;
use Billium\Usage\Laravel\UsageServiceProvider;
use Illuminate\Foundation\Application;
use RuntimeException;
use stdClass;

/**
 * A durable HOST fixture, not a production collector or a default retention/retry policy.
 * Source sequence and its contiguous acknowledgement cursor belong to this host manifest;
 * Billium receives immutable facts and the host's separately sequenced completeness evidence.
 *
 * @phpstan-type BufferedFact array{sequence: int, payload: string}
 * @phpstan-type CollectorState array{sourcePolicyRef: string, repairRef: string, retryBudget: int, bufferedAtMicros: int, retainedUntilMicros: int, acknowledgedSequence: int, nextSequence: int, evidenceSequence: int, buffer: list<BufferedFact>, failedAttempts: list<array{attempt: int, sequence: int, error: string}>, deliveries: list<array{sequence: int, receipt: string, digest: string}>, processes: list<int>, repaired: bool}
 */
final readonly class CollectorRestartScenario implements Scenario
{
    public function __construct(Application $app, int $index)
    {
        $app->instance(CallerContext::class, new FixedCallerContext(new Caller(
            sellerRef: 'seller-a', environment: 'test', installationRef: 'inst-test',
            actorRef: 'collector-host-'.$index, sourceAuthorities: ['tariffler'],
        )));
        $app->register(UsageServiceProvider::class);
        $app->instance(UsageAttribution::class, new UsageFixtures);
    }

    /** @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function run(Application $app, int $index, array $args): array
    {
        $path = $args['statePath'];
        $phase = $args['phase'];
        $lease = $args['lease'];
        $now = $args['nowMicros'];
        assert(is_string($path) && is_string($phase) && is_string($lease) && is_int($now));
        // Join the parent's verified live suite lease; never reset fixtures in a child.
        putenv('BILLIUM_TEST_SUITE_LEASE='.$lease);
        TestEnvironment::acquireSuiteLease();

        if ($phase === 'exhaust') {
            return $this->exhaust($app, $path, $args);
        }
        if ($phase !== 'reconcile') {
            throw new RuntimeException('Unknown collector fixture phase');
        }

        $state = self::state($path);
        $state['processes'][] = self::pid();
        if ($now > $state['retainedUntilMicros']) {
            throw new RuntimeException('Fixture buffer retention expired; sequence cannot be guessed');
        }
        $codec = new Codec;
        foreach ($state['buffer'] as $buffered) {
            if ($buffered['sequence'] !== $state['acknowledgedSequence'] + 1) {
                throw new RuntimeException('Source reconciliation encountered a sequence gap');
            }
            $fact = $codec->decode($buffered['payload']);
            assert($fact instanceof UsageFactV2);
            $first = $app->make(Usage::class)->submitUsageFact($fact);
            // An uncertain acknowledgement causes the SAME retained fact to be sent again.
            $again = $app->make(Usage::class)->submitUsageFact($fact);
            if ($codec->encode($first) !== $codec->encode($again)) {
                throw new RuntimeException('A source sequence was admitted with two different receipts');
            }
            foreach ([$first, $again] as $receipt) {
                $state['deliveries'][] = [
                    'sequence' => $buffered['sequence'], 'receipt' => $receipt->receiptRef, 'digest' => $receipt->digest,
                ];
            }
            $state['acknowledgedSequence'] = $buffered['sequence'];
            self::save($path, $state);
        }
        if ($state['acknowledgedSequence'] !== $state['nextSequence'] - 1) {
            throw new RuntimeException('Cannot publish complete evidence with missing source sequences');
        }
        $state['evidenceSequence'] = 3;
        $state['repaired'] = true;
        // Persist repair evidence before publishing it; keep the retained payloads for replay.
        self::save($path, $state);
        self::record($app, 'complete', 3, $state['repairRef']);
        // A delayed lower status must not erase the completed reconciliation.
        self::record($app, 'dropped', 2, 'repair://collector/older-unreconciled-manifest');

        return $state;
    }

    /** @param array<string, mixed> $args
     * @return CollectorState
     */
    private function exhaust(Application $app, string $path, array $args): array
    {
        $budget = $args['retryBudget'];
        $retention = $args['retentionMicros'];
        $now = $args['nowMicros'];
        $policy = $args['sourcePolicyRef'];
        $repair = $args['repairRef'];
        assert(is_int($budget) && $budget > 0 && is_int($retention) && $retention > 0 && is_int($now));
        assert(is_string($policy) && is_string($repair));
        $codec = new Codec;
        $buffer = [];
        foreach ([41 => '7', 42 => '5.000000001'] as $sequence => $quantity) {
            $fact = UsageFixtures::fact(static function (stdClass $raw) use ($sequence, $quantity): void {
                $raw->factId = 'collector-sequence-'.$sequence;
                $raw->value->quantity = $quantity;
                $raw->provenance->sourceRef = 'collector-sequence-'.$sequence;
            });
            $buffer[] = ['sequence' => $sequence, 'payload' => $codec->encode($fact)];
        }
        $state = [
            'sourcePolicyRef' => $policy, 'repairRef' => $repair, 'retryBudget' => $budget,
            'bufferedAtMicros' => $now, 'retainedUntilMicros' => $now + $retention,
            'acknowledgedSequence' => 40, 'nextSequence' => 43, 'evidenceSequence' => 1,
            'buffer' => $buffer, 'failedAttempts' => [], 'deliveries' => [], 'processes' => [self::pid()], 'repaired' => false,
        ];
        self::save($path, $state);
        $attribution = $app->make(UsageAttribution::class);
        assert($attribution instanceof UsageFixtures);
        // Controlled host mapping outage: every retry actually reaches the public Usage port.
        $attribution->unresolved = true;
        $pending = $codec->decode($buffer[0]['payload']);
        assert($pending instanceof UsageFactV2);
        for ($attempt = 1; $attempt <= $budget; $attempt++) {
            try {
                $app->make(Usage::class)->submitUsageFact($pending);
                throw new RuntimeException('Controlled delivery failure unexpectedly admitted the source fact');
            } catch (ProblemException $exception) {
                if ($exception->problem !== ProblemCode::ValidationInvalid
                    || $exception->detail !== 'unresolved attribution or interval requires pre-split normalized facts') {
                    throw $exception;
                }
                $state['failedAttempts'][] = ['attempt' => $attempt, 'sequence' => 41, 'error' => $exception->problem->value];
                self::save($path, $state);
            }
        }
        if (count($state['failedAttempts']) !== $budget) {
            throw new RuntimeException('Retry budget was not exhausted');
        }
        self::record($app, 'dropped', $state['evidenceSequence'], $repair);

        return $state;
    }

    /** @return CollectorState */
    public static function state(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Durable collector manifest is unavailable');
        }
        /** @var CollectorState $state */
        $state = json_decode($json, true, 64, JSON_THROW_ON_ERROR);

        return $state;
    }

    /** @param CollectorState $state */
    private static function save(string $path, array $state): void
    {
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        $handle = fopen($path.'.tmp', 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open durable collector manifest');
        }
        try {
            if (fwrite($handle, $json) !== strlen($json) || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('Unable to persist durable collector manifest');
            }
        } finally {
            fclose($handle);
        }
        if (! rename($path.'.tmp', $path)) {
            throw new RuntimeException('Unable to publish durable collector manifest');
        }
    }

    private static function record(Application $app, string $status, int $sequence, string $repair): void
    {
        $app->make(SourceCompletenessIntake::class)->record(new RecordSourceCompleteness(
            sellerRef: 'seller-a', environment: 'test',
            evidence: AggregationVectors::evidence([
                'src' => 'tariffler', 'seq' => $sequence, 'status' => $status,
                'range' => [1790812800, 1793491200], 'watermark' => 1793491500, 'repair' => $repair,
            ]),
        ));
    }

    private static function pid(): int
    {
        $pid = getmypid();
        if ($pid === false) {
            throw new RuntimeException('Unable to identify collector process');
        }

        return $pid;
    }
}
