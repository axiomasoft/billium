<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Feature;

use Billium\Usage\Data\FactRevision;
use Billium\Usage\Data\FreezeAggregate;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Enums\LateClass;
use Billium\Usage\Internal\Storage\AggregateStore;
use Billium\Usage\Internal\Storage\EffectiveFactSource;
use Billium\Usage\Tests\AggregationTestCase;
use Billium\Usage\Tests\Support\ReplayFactSource;
use Billium\Usage\Tests\Support\UsageFixtures;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

/** Large frozen windows: statement counts stay bounded by chunks, never by the number of facts. */
#[Group('pg')]
final class AggregationMembershipWriteTest extends AggregationTestCase
{
    private const int FACTS = 2 * AggregateStore::INSERT_CHUNK + 1;

    public function test_freeze_writes_large_distinct_membership_in_chunked_statements(): void
    {
        $window = $this->largeDistinctWindow();
        $writes = ['member' => 0, 'distinct' => 0];
        $this->onQuery(static function (string $sql) use (&$writes): void {
            if (str_starts_with($sql, 'insert into "billium_usage_aggregate_member"')) {
                $writes['member']++;
            } elseif (str_starts_with($sql, 'insert into "billium_usage_aggregate_distinct_member"')) {
                $writes['distinct']++;
            }
        });

        $body = $this->aggregation()->freeze(new FreezeAggregate($window))->body();

        self::assertSame((string) (self::FACTS + 1), $body['quantity']);
        self::assertSame(['member' => 3, 'distinct' => 5], $writes);
        self::assertSame(self::FACTS, $this->rows('billium_usage_aggregate_member'));
        self::assertSame(2 * self::FACTS, $this->rows('billium_usage_aggregate_distinct_member'));
        self::assertSame(2, $this->rows('billium_usage_aggregate_distinct_member', 'member_id=?', [hash('sha256', 'member-1')]));
        self::assertCount(self::FACTS, $this->queries()->snapshot(self::text($body['snapshotRef']))['effectiveRevisions']);
    }

    public function test_late_fact_classification_probes_one_member_instead_of_loading_the_snapshot(): void
    {
        $window = $this->largeDistinctWindow();
        $this->aggregation()->freeze(new FreezeAggregate($window));
        $reads = 0;
        $this->onQuery(static function (string $sql) use (&$reads): void {
            if (str_contains($sql, '"billium_usage_aggregate_member"')) {
                $reads++;
                self::assertStringContainsString('"fact_id" = ?', $sql);
            }
        });

        $member = $this->queries()->classifyLateFact(window: $window, observedAtMicros: $window->startMicros + 7, factId: 'fact-00007', factRevision: 1);
        $revised = $this->queries()->classifyLateFact(window: $window, observedAtMicros: $window->startMicros + 7, factId: 'fact-00007', factRevision: 2);
        $unknown = $this->queries()->classifyLateFact(window: $window, observedAtMicros: $window->startMicros + 7, factId: 'late', factRevision: 1);

        self::assertSame(LateClass::OpenWindow, $member->classification);
        self::assertSame(LateClass::PostFinal, $revised->classification);
        self::assertSame(LateClass::PostFinal, $unknown->classification);
        self::assertSame(3, $reads);
    }

    private function largeDistinctWindow(): WindowSelector
    {
        $metric = UsageFixtures::metric(static function (stdClass $raw): void {
            $raw->metricVersion = 'members/1';
            $raw->model = (object) ['id' => 'exact_distinct', 'version' => 1, 'reducer' => 'set_union'];
            $raw->extraction = (object) ['kind' => 'member_field', 'field' => 'members'];
        });
        $this->usage()->registerMetric($metric);
        $this->policy(metric: $metric, algorithm: 'distinct_exact_union', scale: 0);
        $this->completeness();
        $window = $this->window($metric);
        $source = new ReplayFactSource($metric->digest);
        for ($i = 0; $i < self::FACTS; $i++) {
            // Neighbouring facts share one member, and a repeated member is stored once per fact.
            $source->revisions[] = new FactRevision(
                factId: sprintf('fact-%05d', $i), factRevision: 1, factDigest: 'sha256:'.hash('sha256', 'fact-'.$i),
                retracted: false, observedAtMicros: $window->startMicros + $i, receivedAtMicros: $window->startMicros + $i,
                ingestSeq: $i + 1, sourceRef: 'tariffler', sourceSequence: null, quantityUnscaled: '0',
                memberIds: [hash('sha256', 'member-'.$i), hash('sha256', 'member-'.($i + 1)), hash('sha256', 'member-'.$i)],
            );
        }
        $this->application()->instance(EffectiveFactSource::class, $source);

        return $window;
    }

    /** @param callable(string): void $observe */
    private function onQuery(callable $observe): void
    {
        $this->application()->make(Dispatcher::class)->listen(QueryExecuted::class, static function (QueryExecuted $query) use ($observe): void {
            $observe($query->sql);
        });
    }
}
