<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\Caller;
use Billium\Core\Data\OperationSpec;
use Billium\Core\Enums\FinancialMode;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Usage\Contracts\AggregationParticipants;
use Billium\Usage\Data\AggregateSnapshotRef;
use Billium\Usage\Data\FreezeAggregate;
use Billium\Usage\Data\FreezeReceipt;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Enums\LateClass;
use Billium\Usage\Enums\LatePolicy;
use Billium\Usage\Internal\Storage\DatabaseEffectiveFactSource;
use Billium\Usage\Internal\Storage\EffectiveFactSource;
use Billium\Usage\Tests\AggregationTestCase;
use Billium\Usage\Tests\Support\ReplayFactSource;
use Billium\Usage\Tests\Support\UsageFixtures;
use Illuminate\Database\QueryException;
use stdClass;

final class AggregationFreezeTest extends AggregationTestCase
{
    public function test_aggregation_freeze_is_immutable_idempotent_and_keeps_atomic_notification(): void
    {
        $this->policy();
        $this->completeness();
        $this->usage()->submitUsageFact(UsageFixtures::fact());
        $first = $this->freeze();
        $second = $this->freeze();
        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame('12.000000001', $second['quantity']);
        self::assertSame($first['snapshotDigest'], $second['snapshotDigest']);
        self::assertSame(1, $this->rows('billium_usage_aggregate_snapshot'));
        self::assertSame(1, $this->rows('billium_usage_aggregate_member'));
        self::assertSame(1, $this->rows('billium_usage_aggregate_event'));
        self::assertSame(0, $this->rows('billium_outbox_event'));
        self::assertSame(0, $this->rows('billium_obligation'));
        foreach (['billium_usage_aggregate_snapshot', 'billium_usage_aggregate_member', 'billium_usage_aggregate_event',
            'billium_usage_source_completeness', 'billium_usage_aggregation_policy', 'billium_usage_source_finality_contract'] as $table) {
            try {
                $this->owner()->statement("DELETE FROM {$table}");
                self::fail('Append-only table was mutable: '.$table);
            } catch (QueryException $exception) {
                self::assertSame('55000', $exception->getCode());
            }
        }
        try {
            $this->owner()->statement('UPDATE billium_usage_aggregate_snapshot SET quantity=0');
            self::fail('snapshot was rewritten');
        } catch (QueryException $exception) {
            self::assertSame('55000', $exception->getCode());
        }
    }

    public function test_aggregation_late_correction_reopens_original_window_without_rewriting_predecessor(): void
    {
        $this->policy();
        $this->completeness();
        $fact = UsageFixtures::fact();
        $this->usage()->submitUsageFact($fact);
        $first = $this->freeze();
        $before = $this->value('SELECT row_to_json(s)::text FROM billium_usage_aggregate_snapshot s WHERE snapshot_revision=1');
        $this->usage()->submitUsageFact(UsageFixtures::correction(previous: $fact, quantity: '10'));
        $status = $this->queries()->windowStatus($this->window());
        self::assertSame(1, $status['lagFacts']);
        self::assertSame('pending', $status['progress']);
        self::assertArrayNotHasKey('quantity', $status);
        $this->domainRefusal(fn () => $this->assertCurrent($first), 'snapshot_superseded');
        $late = $this->queries()->classifyLateFact(
            window: $this->window(), observedAtMicros: self::micros($fact->eventAt),
            factId: DatabaseEffectiveFactSource::identity(source: $fact->source, factId: $fact->factId), factRevision: 2,
        );
        self::assertSame(LateClass::PostFinal, $late->classification);
        self::assertSame($this->window()->startMicros, $late->window->startMicros);
        $second = $this->freeze($first['snapshotDigest']);
        self::assertSame(2, $second['snapshotRevision']);
        self::assertSame('10.000000000', $second['quantity']);
        self::assertSame($first['snapshotDigest'], $this->queries()->snapshot($second['snapshotRef'])['predecessorDigest']);
        self::assertSame($before, $this->value('SELECT row_to_json(s)::text FROM billium_usage_aggregate_snapshot s WHERE snapshot_revision=1'));
        $this->domainRefusal(fn () => $this->assertCurrent($first), 'snapshot_superseded');
        $this->assertCurrent($second);
        $this->domainRefusal(fn () => $this->queries()->classifyLateFact(
            window: $this->window(), observedAtMicros: $this->window()->endMicros, factId: 'late', factRevision: 1,
        ), 'original_window_mismatch');
    }

    public function test_aggregation_reject_and_manual_review_leave_final_snapshot_unchanged(): void
    {
        $this->policy(late: LatePolicy::Reject);
        $this->completeness();
        $fact = UsageFixtures::fact();
        $this->usage()->submitUsageFact($fact);
        $first = $this->freeze();
        $this->usage()->submitUsageFact(UsageFixtures::correction($fact));
        $this->domainRefusal(fn () => $this->freeze(), 'late_rejected');
        self::assertSame(1, $this->rows('billium_usage_aggregate_snapshot'));
        self::assertSame($first['snapshotDigest'], $this->value('SELECT head_digest FROM billium_usage_aggregate_series_head'));
    }

    public function test_aggregation_manual_review_and_disabled_reopening_refuse_revision_two(): void
    {
        $this->policy(late: LatePolicy::ManualReview);
        $this->completeness();
        $fact = UsageFixtures::fact();
        $this->usage()->submitUsageFact($fact);
        $this->freeze();
        $this->usage()->submitUsageFact(UsageFixtures::correction($fact));
        $this->domainRefusal(fn () => $this->freeze(), 'late_manual_review');
        self::assertSame(1, $this->rows('billium_usage_aggregate_snapshot'));
    }

    public function test_aggregation_dropped_retry_exhaustion_and_expired_retention_never_produce_final_zero(): void
    {
        $this->policy();
        foreach (['dropped', 'retention_expired', 'delayed', 'gap'] as $i => $status) {
            $this->completeness(status: $status, sequence: $i + 1, repair: 'repair://collector/'.$status);
            $command = new FreezeAggregate(window: $this->window(), commandKey: 'held-'.$i);
            $this->domainRefusal(fn () => $this->aggregation()->freeze($command), 'window_held');
            $query = $this->queries()->windowStatus($this->window());
            self::assertSame('held', $query['state']);
            self::assertSame(['repair://collector/'.$status], $query['repairOrReplayRefs']);
            self::assertArrayNotHasKey('quantity', $query);
            self::assertSame(0, $this->rows('billium_usage_aggregate_snapshot'));
            self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind='usage.aggregate.freeze.v1'"));
        }
        // A late delivery of lower evidence_seq cannot paint over the source's newer repair.
        $this->completeness(sequence: 6);
        $this->completeness(status: 'dropped', sequence: 5, repair: 'repair://old');
        self::assertSame('qualified', $this->queries()->windowStatus($this->window())['state']);
        self::assertSame('0.000000000', $this->freeze()['quantity']);
    }

    public function test_aggregation_accepted_fact_is_pending_without_a_false_complete_zero(): void
    {
        $this->policy();
        $this->usage()->submitUsageFact(UsageFixtures::fact());
        $status = $this->queries()->windowStatus($this->window());
        self::assertSame('open', $status['state']);
        self::assertSame(1, $status['lagFacts']);
        self::assertArrayNotHasKey('quantity', $status);
        $this->domainRefusal(fn () => $this->freeze(), 'window_held');
        self::assertSame(0, $this->rows('billium_usage_aggregate_series_head'));
    }

    public function test_aggregation_expected_head_compare_and_explicit_command_digest_conflict(): void
    {
        $this->policy();
        $this->completeness();
        $fact = UsageFixtures::fact();
        $this->usage()->submitUsageFact($fact);
        $command = new FreezeAggregate(window: $this->window(), commandKey: 'freeze-key');
        $one = $this->aggregation()->freeze($command);
        self::assertSame($one->body(), $this->aggregation()->freeze($command)->body());
        $this->usage()->submitUsageFact(UsageFixtures::correction($fact));
        $this->domainRefusal(fn () => $this->freeze('sha256:stale'), 'snapshot_superseded');
        try {
            $this->aggregation()->freeze(new FreezeAggregate(window: $this->window(), expectedHeadDigest: 'different', commandKey: 'freeze-key'));
            self::fail('same key with changed payload was accepted');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::IdempotencyConflict, $exception->problem);
        }
        self::assertSame(1, $this->rows('billium_usage_aggregate_snapshot'));
    }

    public function test_aggregation_completeness_replay_conflict_and_source_authority(): void
    {
        $this->completeness();
        $this->completeness();
        self::assertSame(1, $this->rows('billium_usage_source_completeness'));
        try {
            $this->completeness(status: 'dropped', repair: 'repair://changed');
            self::fail('evidence digest conflict accepted');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::IdempotencyConflict, $exception->problem);
        }
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'unauthorized', []));
        $this->domainRefusal(fn () => $this->completeness(sequence: 2), 'scope_denied');
    }

    public function test_aggregation_distinct_retains_shared_member_contributions_and_erasure_refuses_replay(): void
    {
        $metric = UsageFixtures::metric(static function (stdClass $raw): void {
            $raw->metricVersion = 'members/1';
            $raw->model = (object) ['id' => 'exact_distinct', 'version' => 1, 'reducer' => 'set_union'];
            $raw->extraction = (object) ['kind' => 'member_field', 'field' => 'members'];
        });
        $this->usage()->registerMetric($metric);
        $this->policy(metric: $metric, algorithm: 'distinct_exact_union', scale: 0);
        $this->completeness();
        $make = static fn (string $id, array $seeds) => UsageFixtures::fact(static function (stdClass $raw) use ($id, $seeds, $metric): void {
            $raw->factId = $id;
            $raw->metricVersion = $metric->metricVersion;
            $raw->metricDigest = $metric->digest;
            $raw->value = (object) ['kind' => 'members', 'members' => array_map(static fn (string $s): string => hash('sha256', $s), $seeds)];
        });
        $a = $make('A', ['m1', 'm2']);
        $this->usage()->submitUsageFact($a);
        $this->usage()->submitUsageFact($make('B', ['m2', 'm3']));
        $window = $this->window($metric);
        $first = $this->aggregation()->freeze(new FreezeAggregate($window))->body();
        self::assertSame('3', $first['quantity']);
        $raw = clone $a->raw;
        $raw->revision = 2;
        $raw->value = (object) ['kind' => 'members', 'members' => [hash('sha256', 'm4')]];
        $raw->correction = (object) ['correctionRef' => 'distinct-correction', 'supersedesRevision' => 1, 'originalFactDigest' => $a->digest];
        $codec = new Codec;
        $raw->digest = $codec->digest($raw);
        $this->usage()->submitUsageFact($codec->decode($codec->canonicalize($raw)));
        $second = $this->aggregation()->freeze(new FreezeAggregate($window))->body();
        self::assertSame('3', $second['quantity']);
        self::assertNotSame($first['snapshotDigest'], $second['snapshotDigest']);
        self::assertSame(3, $this->rows('billium_usage_aggregate_distinct_member', 'snapshot_id=(SELECT id FROM billium_usage_aggregate_snapshot WHERE snapshot_revision=2)'));
        self::assertSame(1, $this->rows('billium_usage_aggregate_distinct_member', 'snapshot_id=(SELECT id FROM billium_usage_aggregate_snapshot WHERE snapshot_revision=2) AND member_id=?', [hash('sha256', 'm2')]));
        $id = $this->value('SELECT id FROM billium_usage_aggregate_snapshot WHERE snapshot_revision=2');
        try {
            self::connection('pgsql')->statement("SELECT public.billium_usage_erase_distinct_members('seller-a','test',ARRAY[{$id}]::bigint[])");
            self::fail('runtime may not erase retained membership');
        } catch (QueryException $exception) {
            self::assertSame('42501', $exception->getCode());
        }
        $this->owner()->statement("SELECT public.billium_usage_erase_distinct_members('seller-a','test',ARRAY[{$id}]::bigint[])");
        self::assertSame('erased', $this->queries()->snapshot($second['snapshotRef'])['reproducibility']);
        self::assertSame(0, $this->rows('billium_usage_aggregate_distinct_member', 'snapshot_id=? AND member_id IS NOT NULL', [$id]));
        $this->domainRefusal(fn () => $this->aggregation()->freeze(new FreezeAggregate($window)), 'not_reproducible');
        $this->domainRefusal(fn () => $this->assertCurrent($second), 'not_reproducible');
    }

    public function test_aggregation_notification_failure_rolls_back_snapshot_membership_head_and_claim(): void
    {
        $this->policy();
        $this->completeness();
        $this->usage()->submitUsageFact(UsageFixtures::fact());
        $this->owner()->unprepared(<<<'SQL'
            CREATE FUNCTION public.billium_usage_test_event_failure() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN RAISE EXCEPTION 'event failure'; END $$;
            CREATE TRIGGER billium_usage_test_event_failure BEFORE INSERT ON public.billium_usage_aggregate_event
                FOR EACH ROW EXECUTE FUNCTION public.billium_usage_test_event_failure();
            SQL);
        try {
            try {
                $this->aggregation()->freeze(new FreezeAggregate(window: $this->window(), commandKey: 'rolled-back'));
                self::fail('notification failure was ignored');
            } catch (QueryException $exception) {
                self::assertStringContainsString('event failure', $exception->getMessage());
            }
        } finally {
            $this->owner()->unprepared('DROP TRIGGER billium_usage_test_event_failure ON public.billium_usage_aggregate_event');
            $this->owner()->unprepared('DROP FUNCTION public.billium_usage_test_event_failure()');
        }
        foreach (['billium_usage_aggregate_snapshot', 'billium_usage_aggregate_member', 'billium_usage_aggregate_event', 'billium_usage_aggregate_series_head'] as $table) {
            self::assertSame(0, $this->rows($table));
        }
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind='usage.aggregate.freeze.v1'"));
    }

    public function test_aggregation_historical_read_and_window_are_seller_scoped(): void
    {
        $this->policy();
        $this->completeness();
        $first = $this->freeze();
        $this->callers->set(new Caller('seller-b', 'test', self::INSTALLATION, 'other', ['tariffler']));
        $this->domainRefusal(fn () => $this->queries()->snapshot($first['snapshotRef']), 'snapshot_unknown');
        $this->domainRefusal(fn () => $this->queries()->windowStatus($this->window()), 'scope_denied');
    }

    private function assertCurrent(array $receipt): void
    {
        $this->runner()->run(
            spec: new OperationSpec(
                commandKind: 'usage.test_current', sellerRef: 'seller-a', environment: 'test',
                mode: FinancialMode::Administrative, operationClass: ModuleOperationClass::NewIntent, modules: ['platform', 'usage'],
            ),
            body: function (TransactionContext $context) use ($receipt): FreezeReceipt {
                $this->application()->make(AggregationParticipants::class)->assertSnapshotCurrent(
                    context: $context, ref: new AggregateSnapshotRef(snapshotRef: $receipt['snapshotRef'], snapshotDigest: $receipt['snapshotDigest']),
                );

                return new FreezeReceipt([]);
            },
        )->orThrow();
    }

    public function test_aggregation_gauge_database_ties_and_carry_follow_the_registered_metric(): void
    {
        $metric = UsageFixtures::metric(static function (stdClass $raw): void {
            $raw->metricVersion = 'gauge/1';
            $raw->model = (object) [
                'id' => 'gauge', 'version' => 1, 'revisionPolicy' => 'highest_fact_revision',
                'sampleReducer' => 'latest', 'tieBreak' => 'eventAt_source_factId',
            ];
        });
        $this->usage()->registerMetric($metric);
        $this->policy(metric: $metric, algorithm: 'gauge_latest_as_of', carry: true);
        $this->completeness();
        foreach (['a' => '5', 'b' => '7'] as $id => $quantity) {
            $this->usage()->submitUsageFact(UsageFixtures::fact(static function (stdClass $raw) use ($metric, $id, $quantity): void {
                $raw->metricVersion = $metric->metricVersion;
                $raw->metricDigest = $metric->digest;
                $raw->factId = $id;
                $raw->value->quantity = $quantity;
            }));
        }
        $window = $this->window($metric);
        self::assertSame('7.000000000', $this->aggregation()->freeze(new FreezeAggregate($window))->body()['quantity']);
        $carried = new WindowSelector(
            sellerRef: $window->sellerRef, environment: $window->environment, metricRef: $window->metricRef,
            metricVersion: $window->metricVersion, groupKey: $window->groupKey,
            startMicros: self::micros('2026-10-18T00:00:00Z'), endMicros: self::micros('2026-10-19T00:00:00Z'),
        );
        $receipt = $this->aggregation()->freeze(new FreezeAggregate($carried))->body();
        self::assertSame('7.000000000', $receipt['quantity']);
        self::assertSame(['carried' => true, 'declaredZero' => false], $this->queries()->snapshot($receipt['snapshotRef'])['reductionEvidence']);
    }

    public function test_aggregation_gauge_declared_zero_is_persisted_as_evidence(): void
    {
        $metric = UsageFixtures::metric(static function (stdClass $raw): void {
            $raw->metricVersion = 'gauge-zero/1';
            $raw->model = (object) [
                'id' => 'gauge', 'version' => 1, 'revisionPolicy' => 'highest_fact_revision',
                'sampleReducer' => 'latest', 'tieBreak' => 'eventAt_source_factId',
            ];
        });
        $this->usage()->registerMetric($metric);
        $this->policy(metric: $metric, algorithm: 'gauge_latest_as_of', missingInitial: 'declared_zero');
        $this->completeness();
        $receipt = $this->aggregation()->freeze(new FreezeAggregate($this->window($metric)))->body();
        self::assertSame('0.000000000', $receipt['quantity']);
        self::assertSame(['carried' => false, 'declaredZero' => true], $this->queries()->snapshot($receipt['snapshotRef'])['reductionEvidence']);
    }

    public function test_aggregation_definition_digest_mismatch_never_writes_a_snapshot(): void
    {
        $this->policy(definitionDigest: 'sha256:'.str_repeat('0', 64));
        $this->completeness();
        $this->domainRefusal(fn () => $this->freeze(), 'snapshot_digest_mismatch');
        self::assertSame(0, $this->rows('billium_usage_aggregate_snapshot'));
    }

    public function test_aggregation_expired_retained_membership_is_ineligible_for_financial_replay(): void
    {
        $this->policy();
        $this->completeness();
        $this->usage()->submitUsageFact(UsageFixtures::fact());
        $receipt = $this->freeze();
        $this->owner()->table('billium_usage_aggregate_reproducibility_event')->insert([
            'snapshot_id' => $this->value('SELECT id FROM billium_usage_aggregate_snapshot'), 'reproducibility' => 'retention_expired',
        ]);
        self::assertSame('retention_expired', $this->queries()->snapshot($receipt['snapshotRef'])['reproducibility']);
        $this->domainRefusal(fn () => $this->freeze(), 'not_reproducible');
        $this->domainRefusal(fn () => $this->assertCurrent($receipt), 'not_reproducible');
        $status = $this->queries()->windowStatus($this->window());
        self::assertSame('held', $status['state']);
        self::assertArrayNotHasKey('quantity', $status);
    }

    public function test_aggregation_missing_source_membership_never_becomes_a_zero_correction(): void
    {
        $this->policy();
        $this->completeness();
        $this->usage()->submitUsageFact(UsageFixtures::fact());
        $this->freeze();
        $this->application()->instance(
            EffectiveFactSource::class,
            new ReplayFactSource(UsageFixtures::metric()->digest),
        );
        $this->domainRefusal(fn () => $this->freeze(), 'not_reproducible');
        $status = $this->queries()->windowStatus($this->window());
        self::assertSame('held', $status['state']);
        self::assertArrayNotHasKey('quantity', $status);
        self::assertSame(1, $this->rows('billium_usage_aggregate_snapshot'));
    }
}
