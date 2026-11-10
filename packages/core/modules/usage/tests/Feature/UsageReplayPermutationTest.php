<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Feature;

use Billium\Core\Tests\Support\FixtureValues;
use Billium\Usage\Internal\Storage\EffectiveFactSource;
use Billium\Usage\Tests\AggregationTestCase;
use Billium\Usage\Tests\Support\AggregationVectors as V;
use Billium\Usage\Tests\Support\ReplayFactSource;
use Billium\Usage\Tests\Support\UsageFixtures;

final class UsageReplayPermutationTest extends AggregationTestCase
{
    public function test_replay_reverse_delivery_uses_the_same_effective_snapshot(): void
    {
        $vector = V::load('replay-permutation');
        $this->replay([$vector['events'], array_reverse($vector['events'])]);
    }

    public function test_every_permutation_freezes_the_same_pinned_quantity_and_digest(): void
    {
        $vector = V::load('replay-permutation');
        self::assertNotSame('PIN_AT_FIRST_GREEN_RUN', $vector['expected']['digest'], 'fixture not pinned');
        $count = $this->replay(V::deliveries($vector['events']));
        self::assertSame(720, $count);
    }

    /** @param iterable<list<array{fact: string, t: int, q?: string, rev?: int, retracted?: bool, src?: string, seq?: int|null, memberSeeds?: list<string>}>> $permutations */
    private function replay(iterable $permutations): int
    {
        $this->policy(scale: 0);
        $this->completeness();
        $source = new ReplayFactSource(UsageFixtures::metric()->digest);
        $this->application()->instance(EffectiveFactSource::class, $source);
        $vector = V::load('replay-permutation');
        $digest = null;
        $count = 0;
        $base = (int) bcdiv((string) $this->window()->startMicros, '1000000', 0);
        foreach ($permutations as $permutation) {
            // The source port models retained, immutable revisions arriving in arbitrary order.
            // P4.2's separate admission protocol retries absent predecessors before exposing them.
            $source->revisions = [];
            $arrival = array_map(static fn (array $e): array => [...$e, 'src' => 'tariffler', 't' => $base + $e['t']], $permutation);
            foreach (V::revisions($arrival) as $revision) {
                $source->revisions[] = $revision;
            }
            $snapshot = $this->freeze();
            $digest ??= FixtureValues::text($snapshot['snapshotDigest']);
            self::assertSame('17', $snapshot['quantity']);
            self::assertSame($digest, $snapshot['snapshotDigest']);
            self::assertSame($vector['expected']['digest'], V::snapshot(raw: $vector, events: $permutation)->snapshotDigest);
            $count++;
        }
        self::assertSame(1, $this->rows('billium_usage_aggregate_snapshot'));
        self::assertSame(1, $this->rows('billium_usage_aggregate_event'));

        return $count;
    }
}
