<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P7;

use Billium\Collection\Enums\NotificationChannel;
use Billium\Core\Tests\P7\Support\CollectionTestCase;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

/** Per-case and per-consent child reads go through the parent id index, never through every child row of the seller. */
#[Group('pg')]
final class ParentRefLookupPlanTest extends CollectionTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function reads(): iterable
    {
        yield 'case steps' => ['billium_collection_step', 'billium_collection_step_case_idx'];
        yield 'case policy migrations' => ['billium_collection_case_policy_migration', 'billium_collection_case_policy_migration_case_idx'];
        yield 'case notifications' => ['billium_collection_notification', 'billium_collection_notification_case_idx'];
        yield 'case signals' => ['billium_collection_host_signal', 'billium_collection_host_signal_case_idx'];
        yield 'case interventions' => ['billium_collection_manual_intervention', 'billium_collection_manual_intervention_case_idx'];
        yield 'consent tombstones' => ['billium_collection_guard_tombstone', 'billium_collection_guard_tomb_seller_ref_environment_consen_key'];
    }

    #[DataProvider('reads')]
    public function test_a_child_read_uses_the_parent_id_index(string $table, string $index): void
    {
        $lookups = [];
        $this->application()->make(Dispatcher::class)->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$lookups, $table): void {
            if (str_contains($query->sql, 'from "'.$table.'"')) {
                $lookups[] = $query;
            }
        });

        $scope = $this->collectionScope();
        $dunning = $this->dunningQueries();
        $intervention = $this->interventionQueries();
        $dunning->listCaseSteps($scope, 'case-x');
        $dunning->listCasePolicyMigrations($scope, 'case-x');
        $intervention->listCaseNotifications($scope, 'case-x');
        $intervention->findNotificationByKey($scope, 'case-x', 1, 'tpl', '1', NotificationChannel::Email);
        $intervention->listCaseSignals($scope, 'case-x');
        $intervention->listCaseManualInterventions($scope, 'case-x');
        $this->consentQueries()->listTombstones($scope, 'consent-x');

        self::assertNotSame([], $lookups);
        $plans = array_map($this->planOf(...), $lookups);
        foreach ($plans as $plan) {
            self::assertStringNotContainsString('"Node Type": "Seq Scan"', $plan);
        }
        foreach ($plans as $plan) {
            // A (seller_ref, environment) prefix scan filtered by the copied ref would also avoid a Seq Scan; the parent id must be
            // a key. PostgreSQL 16 prints the InitPlan output as $0, PostgreSQL 17+ as (InitPlan 1).col1.
            self::assertMatchesRegularExpression('/"Index Cond": "[^"]*(dunning_case_id|consent_guard_id) = (\\$\\d|\\(InitPlan)/', $plan);
        }
        self::assertTrue(array_any($plans, static fn (string $plan): bool => str_contains($plan, '"Index Name": "'.$index.'"')), implode("\n", $plans));
    }

    private function planOf(QueryExecuted $lookup): string
    {
        $owner = $this->application()->make(DatabaseManager::class)->connection('billium_owner');
        $owner->beginTransaction();
        try {
            // The fixture tables are empty; forbidding the sequential scan shows whether a per-case index serves the read.
            $owner->statement('SET LOCAL enable_seqscan = off');
            $explain = $owner->selectOne('EXPLAIN (FORMAT JSON) '.$lookup->sql, $lookup->bindings);
        } finally {
            $owner->rollBack();
        }
        self::assertInstanceOf(stdClass::class, $explain);
        $plan = $explain->{'QUERY PLAN'};
        self::assertIsString($plan);

        return $plan;
    }
}
