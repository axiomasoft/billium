<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P7\Support;

use Billium\Contracts\Data\Envelopes\UsageFactV2;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\Support\FixtureValues;
use Billium\Usage\Contracts\AggregationService;
use Billium\Usage\Contracts\SourceCompletenessIntake;
use Billium\Usage\Contracts\Usage;
use Billium\Usage\Contracts\UsageAttribution;
use Billium\Usage\Data\AttributionSnapshot;
use Billium\Usage\Data\Commands\RecordSourceCompleteness;
use Billium\Usage\Data\FreezeAggregate;
use Billium\Usage\Data\SourceFinalityContract;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Enums\LatePolicy;
use Billium\Usage\Internal\Canonical\CanonicalJson;
use Billium\Usage\Tests\Support\AggregationVectors;
use Billium\Usage\Tests\Support\UsageFixtures;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Assert;

final class RecurringUsageFixture
{
    public const string START = '2026-10-01T00:00:00Z';

    public const string END = '2026-11-01T00:00:00Z';

    public static function fact(): UsageFactV2
    {
        return UsageFixtures::fact(static function ($fact): void {
            $fact->value->quantity = '10';
            $fact->attribution->billingAccountRef = 'account-a';
            $fact->attribution->customerRef = 'customer-a';
            $fact->attribution->agreementRef = 'agreement-a';
            $fact->attribution->componentRef = 'component-usage';
        });
    }

    public static function freeze(Application $app, Connection $owner, UsageFactV2 $fact): string
    {
        $metric = UsageFixtures::metric();
        $app->instance(UsageAttribution::class, new readonly class($fact) implements UsageAttribution
        {
            public function __construct(private UsageFactV2 $approved) {}

            public function resolve(Caller $caller, UsageFactV2 $fact): ?AttributionSnapshot
            {
                return new AttributionSnapshot($caller->sellerRef, $this->approved->attribution, 'mapping-1',
                    RecurringUsageFixture::START, RecurringUsageFixture::END, 'october-2026',
                    RecurringUsageFixture::START, RecurringUsageFixture::END);
            }
        });
        $usage = $app->make(Usage::class);
        $usage->registerMetric($metric);
        self::seedPolicy($owner, $metric->metricRef, $metric->metricVersion, $metric->digest);

        $accepted = $usage->submitUsageFact($fact);
        Assert::assertSame('accepted', $accepted->result);
        Assert::assertEquals($accepted, $usage->submitUsageFact($fact));
        $start = (new DateTimeImmutable(self::START))->getTimestamp();
        $end = (new DateTimeImmutable(self::END))->getTimestamp();
        $app->make(SourceCompletenessIntake::class)->record(new RecordSourceCompleteness(
            sellerRef: 'seller-a', environment: 'test',
            evidence: AggregationVectors::evidence([
                'src' => 'tariffler', 'seq' => 1, 'status' => 'complete',
                'range' => [$start, $end], 'watermark' => $end + 300,
            ]),
        ));
        $snapshot = $app->make(AggregationService::class)->freeze(new FreezeAggregate(window: new WindowSelector(
            sellerRef: 'seller-a', environment: 'test', metricRef: $metric->metricRef, metricVersion: $metric->metricVersion,
            groupKey: [
                'region' => 'eu', 'mappingRef' => 'mapping-1', 'periodRef' => 'october-2026',
                'billingAccountRef' => 'account-a', 'customerRef' => 'customer-a', 'subjectRef' => 'subject-1',
                'componentRef' => 'component-usage', 'agreementRef' => 'agreement-a', 'agreementRevision' => '1',
                'attributionVersion' => 'host_snapshot/1',
            ],
            startMicros: $start * 1_000_000, endMicros: $end * 1_000_000,
        )))->body();

        return FixtureValues::text($snapshot['snapshotRef']);
    }

    private static function seedPolicy(Connection $owner, string $metricRef, string $metricVersion, string $metricDigest): void
    {
        $contract = new SourceFinalityContract(
            contractRef: 'source-finality', version: 1, requiredSources: ['tariffler'], lateGraceMicros: 300_000_000,
            postFinalPolicy: LatePolicy::AcceptAdjust, reopenAllowed: true,
        );
        $owner->table('billium_usage_source_finality_contract')->insert([
            'seller_ref' => 'seller-a', 'environment' => 'test', 'contract_ref' => $contract->contractRef, 'version' => 1,
            'required_sources' => '["tariffler"]', 'late_grace_micros' => $contract->lateGraceMicros,
            'post_final_policy' => $contract->postFinalPolicy->value, 'reopen_allowed' => $contract->reopenAllowed,
            'contract_digest' => CanonicalJson::digest($contract->payload()),
        ]);
        $owner->table('billium_usage_aggregation_policy')->insert([
            'seller_ref' => 'seller-a', 'environment' => 'test', 'metric_ref' => $metricRef,
            'metric_version' => $metricVersion, 'metric_definition_digest' => $metricDigest,
            'semantics' => 'additive', 'algorithm_id' => 'additive_sum', 'algorithm_version' => 1,
            'missing_initial' => 'refuse', 'carry_forward' => false, 'quantity_scale' => 9, 'rounding_mode' => 'half_up',
            'allow_negative' => false, 'finality_contract_ref' => $contract->contractRef, 'finality_contract_version' => 1,
            'cross_source_identity_policy' => 'shared',
        ]);
    }
}
