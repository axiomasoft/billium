<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
#[Group('PF-02')]
#[Group('PF-06')]
final class ProviderFactIdentityTest extends ExecutionTestCase
{
    #[Group('PB-07')]
    #[Group('PB-08')]
    #[Group('ACC-CON-05')]
    public function test_signed_revisions_preserve_observed_applied_and_exposure_and_never_allocate_an_over_refund(): void
    {
        $journal = Fixture::tempPath();
        try {
            $app = $this->application();
            $first = CallbackCustodyTest::dispatched($app, $journal);
            $reducer = $app->make(EvidenceReducer::class);
            $intake = $app->make(CallbackCustody::class);
            $reduce = static function (array $fields) use ($intake, $reducer, $first) {
                /** @var array<string,mixed> $fields */
                $delivery = CallbackCustodyTest::signed($first, $fields);
                $intake->receive(Fixture::scope(), 'merchant-a', 'root-a', $delivery)->orThrow();

                return $reducer->reduce(CallbackCustodyTest::work($delivery));
            };
            $zero = ['amount' => '0.00', 'currency' => 'RUB'];
            self::assertSame(ReductionDisposition::Applied, $reducer->reduce(CallbackCustodyTest::work($first))->disposition);
            $duplicate = $reduce(['revision' => '2', 'observedAt' => '2026-11-17T13:00:00Z']);
            self::assertSame(ReductionDisposition::Duplicate, $duplicate->disposition);
            $stale = $reduce(['revision' => '0', 'status' => 'pending', 'authorized' => $zero, 'captured' => $zero, 'operationAmount' => $zero]);
            self::assertSame(ReductionDisposition::Stale, $stale->disposition);
            $consistent = $reduce(['revision' => '3', 'refunded' => ['amount' => '0.70', 'currency' => 'RUB']]);
            self::assertSame(ReductionDisposition::Applied, $consistent->disposition);
            $excess = $reduce(['revision' => '4', 'refunded' => ['amount' => '1.40', 'currency' => 'RUB']]);
            self::assertSame(ReductionDisposition::HeldDiscrepancy, $excess->disposition);
            self::assertNotNull($excess->discrepancyRef);
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND confirmed_refund_minor=70 AND financial_hold'));
            self::assertSame(3, $this->rows('billium_execution_observation_emission'));
            self::assertSame(3, self::integer($this->value('SELECT last_sequence FROM billium_execution_observation_partition')));
            $revision = $this->row('SELECT observed,exposure,last_consistent_applied FROM billium_execution_operation_revision ORDER BY revision DESC LIMIT 1');
            self::assertSame(140, self::integer($this->value("SELECT (observed->'refundedTotal'->>'minor')::bigint FROM billium_execution_operation_revision ORDER BY revision DESC LIMIT 1")));
            self::assertStringContainsString('40', self::text($revision['exposure']));
            self::assertSame(70, self::integer($this->value("SELECT (last_consistent_applied->'confirmedRefund'->>'minor')::bigint FROM billium_execution_operation_revision ORDER BY revision DESC LIMIT 1")));
            $unchanged = $reduce(['revision' => '10', 'refunded' => ['amount' => '1.40', 'currency' => 'RUB']]);
            self::assertSame(ReductionDisposition::Duplicate, $unchanged->disposition);
            $olderIncrease = $reduce(['revision' => '7', 'refunded' => ['amount' => '1.60', 'currency' => 'RUB']]);
            self::assertSame(ReductionDisposition::Quarantined, $olderIncrease->disposition);
            self::assertSame(1, $this->rows('billium_execution_discrepancy', "exposure->>'reason'='older_revision_increases_totals'"));
            $regression = $reduce(['revision' => '11', 'captured' => ['amount' => '0.80', 'currency' => 'RUB'], 'operationAmount' => ['amount' => '0.80', 'currency' => 'RUB']]);
            self::assertSame(ReductionDisposition::Quarantined, $regression->disposition);
            self::assertSame(3, $this->rows('billium_execution_observation_emission'));
            self::assertSame(1, UnknownResultTest::events($journal, 'provider.call'));
        } finally {
            UnknownResultTest::clean($journal);
        }
    }

    public function test_decline_followed_by_late_positive_and_overcapture_preserves_both_signed_facts_and_blocks_spend(): void
    {
        $journal = Fixture::tempPath();
        try {
            $app = $this->application();
            $first = CallbackCustodyTest::dispatched($app, $journal, ResponseMode::Declined);
            $intake = $app->make(CallbackCustody::class);
            $reducer = $app->make(EvidenceReducer::class);
            self::assertSame(ReductionDisposition::Applied, $reducer->reduce(CallbackCustodyTest::work($first))->disposition);
            self::assertSame(1, $this->rows('billium_execution_attempt', "state='failed'"));
            $one = ['amount' => '1.00', 'currency' => 'RUB'];
            $late = CallbackCustodyTest::signed($first, ['revision' => '2', 'status' => 'succeeded', 'authorized' => $one, 'captured' => $one, 'operationAmount' => $one]);
            $intake->receive(Fixture::scope(), 'merchant-a', 'root-a', $late)->orThrow();
            self::assertSame(ReductionDisposition::HeldDiscrepancy, $reducer->reduce(CallbackCustodyTest::work($late))->disposition);
            self::assertSame(1, $this->rows('billium_execution_discrepancy', "exposure->>'reason'='late_positive_after_no_effect'"));
            $over = CallbackCustodyTest::signed($late, ['revision' => '3', 'captured' => ['amount' => '1.50', 'currency' => 'RUB']]);
            $intake->receive(Fixture::scope(), 'merchant-a', 'root-a', $over)->orThrow();
            self::assertSame(ReductionDisposition::HeldDiscrepancy, $reducer->reduce(CallbackCustodyTest::work($over))->disposition);
            self::assertSame(1, $this->rows('billium_execution_operation_revision', "(exposure->'overCaptured'->>'minor')::int=50 AND (observed->'capturedTotal'->>'minor')::int=150"));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=0 AND financial_hold'));
            self::assertSame(1, $this->rows('billium_execution_attempt', "state='failed'"));
            self::assertSame(3, $this->rows('billium_execution_provider_fact'));
            self::assertSame(3, $this->rows('billium_execution_observation_emission'));
            $same = CallbackCustodyTest::signed($over, ['stableEffectId' => 'different-event-same-version', 'captured' => ['amount' => '1.60', 'currency' => 'RUB']]);
            $intake->receive(Fixture::scope(), 'merchant-a', 'root-a', $same)->orThrow();
            self::assertSame(ReductionDisposition::Quarantined, $reducer->reduce(CallbackCustodyTest::work($same))->disposition);
            self::assertSame(1, $this->rows('billium_execution_discrepancy', "exposure->>'reason'='provider_revision_conflict'"));
            self::assertSame(3, $this->rows('billium_execution_observation_emission'));
        } finally {
            UnknownResultTest::clean($journal);
        }
    }

    public function test_default_postgres_planner_selects_the_authorized_identity_and_event_indexes_on_representative_history(): void
    {
        $journal = Fixture::tempPath();
        try {
            $app = $this->application();
            $event = CallbackCustodyTest::dispatched($app, $journal);
            $app->make(EvidenceReducer::class)->reduce(CallbackCustodyTest::work($event));
            $db = $this->owner();
            // Physically populate scoped history for plan selection. These rows never enter a financial worker.
            $fixture = [
                'operation' => ['kind' => "'capture'", 'operation_ref' => "'plan-op-'||n"],
                'attempt' => ['operation_ref' => "'plan-op-'||n", 'attempt_ref' => "'plan-attempt-'||n", 'provider_reference' => "'plan-ref-'||n"],
                'provider_evidence' => ['evidence_ref' => "'plan-evidence-'||n", 'provider_event_id' => "'plan-event-'||n"],
                'provider_identity' => ['attempt_ref' => "'plan-attempt-'||n", 'evidence_ref' => "'plan-evidence-'||n", 'provider_operation_id' => "'plan-provider-'||n"],
            ];
            foreach ($fixture as $name => $expressions) {
                $table = 'billium_execution_'.$name;
                $source = $db->table($table)->orderBy('id')->first();
                self::assertNotNull($source);
                $columns = array_keys(get_object_vars($source));
                $columns = array_values(array_diff($columns, ['id']));
                $values = array_map(static fn (string $col): string => $expressions[$col] ?? 'source.'.$col, $columns);
                $db->statement('INSERT INTO '.$table.' ('.implode(',', $columns).') SELECT '.implode(',', $values).' FROM (SELECT * FROM '.$table.' ORDER BY id LIMIT 1) source CROSS JOIN generate_series(1,2048) n');
                $db->statement('ANALYZE '.$table);
            }
            $scope = Fixture::scope();
            $raw = $event->sanitizedPayload['material'];
            self::assertIsArray($raw);
            $assigned = self::text($raw['providerOperationId']);
            $ns = SimulatedCapabilities::snapshot()->details->lookupNamespace;
            self::assertSame('root-a', $app->make(ExecutionStore::class)->operationByProviderId($scope, 'simulated-provider', 'account-a', $ns, $assigned)?->operationRef);
            $params = [$scope->installationRef, $scope->sellerRef, $scope->environment];
            $cases = [
                ['billium_execution_assigned_operation', 'SELECT * FROM billium_execution_provider_identity WHERE installation_ref=? AND seller_ref=? AND environment=? AND public.billium_execution_provider_identity_key(environment,provider_id,merchant_account_ref,provider_namespace,provider_operation_id)=public.billium_execution_provider_identity_key(?,?,?,?,?) AND provider_id=? AND merchant_account_ref=? AND provider_namespace=? AND provider_operation_id=? LIMIT 1', [...$params, 'test', 'simulated-provider', 'account-a', $ns, $assigned, 'simulated-provider', 'account-a', $ns, $assigned]],
                ['billium_execution_provider_evidence_lookup', "SELECT * FROM billium_execution_provider_evidence WHERE installation_ref=? AND seller_ref=? AND environment=? AND provider_id=? AND merchant_account_ref=? AND provider_event_id=? AND signature_verdict NOT IN ('unverified','quarantined_conflict') ORDER BY id LIMIT 1", [...$params, 'simulated-provider', 'account-a', $event->providerEventId]],
            ];
            foreach ($cases as [$index,$sql,$bindings]) {
                $plan = self::text($db->scalar('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql, $bindings));
                self::assertStringContainsString($index, $plan);
                self::assertStringNotContainsString('Seq Scan', $plan);
                self::assertMatchesRegularExpression('/"Actual Rows":\s*1\b/', $plan);
                fwrite(STDOUT, "\nRepresentative PostgreSQL query plan ".$index.': '.$plan."\n");
            }
        } finally {
            UnknownResultTest::clean($journal);
        }
    }

    public function test_verified_provider_id_is_separate_from_effect_id_and_both_indexed_routes_are_seller_scoped(): void
    {
        $journal = Fixture::tempPath();
        try {
            $app = $this->application();
            $delivery = CallbackCustodyTest::dispatched($app, $journal);
            $app->make(EvidenceReducer::class)->reduce(CallbackCustodyTest::work($delivery));
            $material = $delivery->sanitizedPayload['material'];
            self::assertIsArray($material);
            $id = self::text($material['providerOperationId']);
            self::assertNotSame($id, $material['stableEffectId']);
            $store = $app->make(ExecutionStore::class);
            self::assertSame($id, $store->providerOperationId(Fixture::scope(), UnknownResultTest::attemptRef()));
            $ns = SimulatedCapabilities::snapshot()->details->lookupNamespace;
            self::assertSame('root-a', $store->operationByProviderId(Fixture::scope(), 'simulated-provider', 'account-a', $ns, $id)?->operationRef);
            self::assertNull($store->operationByProviderId(Fixture::scope(), 'simulated-provider', 'another-account', $ns, $id));
            self::assertNull($store->operationByProviderId(Fixture::scope(), 'simulated-provider', 'account-a', 'wrong-namespace', $id));
            $this->callers->as('seller-other');
            $other = new ExecutionScope(Fixture::scope()->installationRef, 'seller-other', 'test', Fixture::scope()->intentOwnerNamespace);
            self::assertNull($store->operationByProviderId($other, 'simulated-provider', 'account-a', $ns, $id));
            self::assertNull($store->providerWork(CallbackCustodyTest::work($delivery)));
        } finally {
            UnknownResultTest::clean($journal);
        }
    }
}
