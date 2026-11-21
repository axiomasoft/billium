<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Feature;

use Billium\Core\Configuration\BilliumOptions;
use Billium\Usage\Data\FactRevision;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Internal\Storage\DatabaseEffectiveFactSource;
use Billium\Usage\Internal\Storage\EffectiveFactSource;
use Billium\Usage\Tests\AggregationTestCase;
use Billium\Usage\Tests\Support\UsageFixtures;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

/** The window projection checks the metric against its policy once and still projects every fact. */
#[Group('pg')]
final class AggregationFactProjectionTest extends AggregationTestCase
{
    public function test_projection_returns_every_fact_of_the_window(): void
    {
        $this->policy();
        $facts = [];
        foreach (['p-1', 'p-2', 'p-3'] as $id) {
            $fact = UsageFixtures::fact(static function (stdClass $raw) use ($id): void {
                $raw->factId = $id;
            });
            $this->usage()->submitUsageFact($fact);
            $facts[] = DatabaseEffectiveFactSource::identity(source: $fact->source, factId: $fact->factId);
        }

        $revisions = $this->project();

        self::assertSame($facts, array_map(static fn (FactRevision $revision): string => $revision->factId, $revisions));
    }

    public function test_grouping_outside_the_metric_definition_is_refused_with_and_without_facts(): void
    {
        $this->policy();
        $window = $this->window();
        $foreign = new WindowSelector(
            sellerRef: $window->sellerRef, environment: $window->environment, metricRef: $window->metricRef,
            metricVersion: $window->metricVersion, groupKey: [...$window->groupKey, 'zone' => 'north'],
            startMicros: $window->startMicros, endMicros: $window->endMicros,
        );
        $this->domainRefusal(fn (): array => $this->project($foreign), 'metric_policy_mismatch');
        $this->usage()->submitUsageFact(UsageFixtures::fact());
        self::assertCount(1, $this->project());
        $this->domainRefusal(fn (): array => $this->project($foreign), 'metric_policy_mismatch');
    }

    /** @return list<FactRevision> */
    private function project(?WindowSelector $window = null): array
    {
        $connection = $this->application()->make(BilliumOptions::class)->connection;
        self::assertIsString($connection);
        $revisions = $this->application()->make(EffectiveFactSource::class)->revisionsForWindow($window ?? $this->window(), $connection);
        usort($revisions, static fn (FactRevision $a, FactRevision $b): int => strcmp($a->factId, $b->factId));

        return $revisions;
    }
}
