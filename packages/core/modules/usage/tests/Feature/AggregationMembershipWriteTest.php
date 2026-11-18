<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Feature;

use Billium\Usage\Data\FactRevision;
use Billium\Usage\Data\FreezeAggregate;
use Billium\Usage\Internal\Storage\AggregateStore;
use Billium\Usage\Internal\Storage\EffectiveFactSource;
use Billium\Usage\Tests\AggregationTestCase;
use Billium\Usage\Tests\Support\ReplayFactSource;
use Billium\Usage\Tests\Support\UsageFixtures;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

#[Group('pg')]
final class AggregationMembershipWriteTest extends AggregationTestCase
{
    public function test_freeze_writes_large_distinct_membership_in_chunked_statements(): void
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
        $facts = 2 * AggregateStore::INSERT_CHUNK + 1;
        $source = new ReplayFactSource($metric->digest);
        for ($i = 0; $i < $facts; $i++) {
            // Neighbouring facts share one member so distinct contributions differ from the member count.
            $source->revisions[] = new FactRevision(
                factId: sprintf('fact-%05d', $i), factRevision: 1, factDigest: 'sha256:'.hash('sha256', 'fact-'.$i),
                retracted: false, observedAtMicros: $window->startMicros + $i, receivedAtMicros: $window->startMicros + $i,
                ingestSeq: $i + 1, sourceRef: 'tariffler', sourceSequence: null, quantityUnscaled: '0',
                memberIds: [hash('sha256', 'member-'.$i), hash('sha256', 'member-'.($i + 1)), hash('sha256', 'member-'.$i)],
            );
        }
        $this->application()->instance(EffectiveFactSource::class, $source);
        $writes = ['member' => 0, 'distinct' => 0];
        $this->application()->make(Dispatcher::class)->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$writes): void {
            if (str_starts_with($query->sql, 'insert into "billium_usage_aggregate_member"')) {
                $writes['member']++;
            } elseif (str_starts_with($query->sql, 'insert into "billium_usage_aggregate_distinct_member"')) {
                $writes['distinct']++;
            }
        });

        $body = $this->aggregation()->freeze(new FreezeAggregate($window))->body();

        self::assertSame((string) ($facts + 1), $body['quantity']);
        self::assertSame(['member' => 3, 'distinct' => 5], $writes);
        self::assertSame($facts, $this->rows('billium_usage_aggregate_member'));
        self::assertSame(2 * $facts, $this->rows('billium_usage_aggregate_distinct_member'));
        self::assertSame(2, $this->rows('billium_usage_aggregate_distinct_member', 'member_id=?', [hash('sha256', 'member-1')]));
        self::assertSame($facts, count($this->queries()->snapshot(self::text($body['snapshotRef']))['effectiveRevisions']));
    }
}
