<?php

declare(strict_types=1);

namespace Billium\Usage\Tests;

use Billium\Contracts\Data\Envelopes\MetricVersion;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Usage\Contracts\AggregationQueries;
use Billium\Usage\Contracts\AggregationService;
use Billium\Usage\Contracts\SourceCompletenessIntake;
use Billium\Usage\Data\Commands\RecordSourceCompleteness;
use Billium\Usage\Data\FreezeAggregate;
use Billium\Usage\Data\SourceFinalityContract;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Enums\LatePolicy;
use Billium\Usage\Internal\Canonical\CanonicalJson;
use Billium\Usage\Internal\Storage\DatabaseEffectiveFactSource;
use Billium\Usage\Tests\Support\AggregationVectors;
use Billium\Usage\Tests\Support\UsageFixtures;
use DateTimeImmutable;

abstract class AggregationTestCase extends UsageTestCase
{
    protected function aggregation(): AggregationService
    {
        return $this->application()->make(AggregationService::class);
    }

    protected function queries(): AggregationQueries
    {
        return $this->application()->make(AggregationQueries::class);
    }

    protected function window(?MetricVersion $metric = null): WindowSelector
    {
        $metric ??= UsageFixtures::metric();
        $group = ['region' => 'eu', 'mappingRef' => 'mapping-1', 'periodRef' => 'october-2026'];
        foreach (get_object_vars(UsageFixtures::fact()->attribution) as $name => $value) {
            if (array_key_exists($name, DatabaseEffectiveFactSource::ATTRIBUTION)) {
                assert(is_string($value) || is_int($value));
                $group[$name] = (string) $value;
            }
        }

        return new WindowSelector(
            sellerRef: 'seller-a', environment: 'test', metricRef: $metric->metricRef,
            metricVersion: $metric->metricVersion, groupKey: $group,
            startMicros: self::micros('2026-10-01T00:00:00Z'), endMicros: self::micros('2026-11-01T00:00:00Z'),
        );
    }

    protected function policy(
        LatePolicy $late = LatePolicy::AcceptAdjust,
        ?MetricVersion $metric = null,
        string $algorithm = 'additive_sum',
        int $scale = 9,
        bool $carry = false,
        string $missingInitial = 'refuse',
        ?string $definitionDigest = null,
        int $algorithmVersion = 1,
    ): void {
        $metric ??= UsageFixtures::metric();
        $contract = new SourceFinalityContract(
            contractRef: 'source-finality', version: 1, requiredSources: ['tariffler'], lateGraceMicros: 300_000_000,
            postFinalPolicy: $late, reopenAllowed: $late === LatePolicy::AcceptAdjust,
        );
        $this->owner()->table('billium_usage_source_finality_contract')->insert([
            'seller_ref' => 'seller-a', 'environment' => 'test', 'contract_ref' => $contract->contractRef, 'version' => 1,
            'required_sources' => '["tariffler"]', 'late_grace_micros' => $contract->lateGraceMicros,
            'post_final_policy' => $late->value, 'reopen_allowed' => $contract->reopenAllowed,
            'contract_digest' => CanonicalJson::digest($contract->payload()),
        ]);
        $distinct = $algorithm === 'distinct_exact_union';
        $gauge = str_starts_with($algorithm, 'gauge_');
        $this->owner()->table('billium_usage_aggregation_policy')->insert([
            'seller_ref' => 'seller-a', 'environment' => 'test', 'metric_ref' => $metric->metricRef,
            'metric_version' => $metric->metricVersion, 'metric_definition_digest' => $definitionDigest ?? $metric->digest,
            'semantics' => $distinct ? 'distinct_exact' : ($gauge ? 'gauge' : 'additive'),
            'algorithm_id' => $algorithm, 'algorithm_version' => $algorithmVersion, 'gauge_policy' => $gauge ? substr($algorithm, 6) : null,
            'missing_initial' => $missingInitial, 'carry_forward' => $carry, 'quantity_scale' => $scale, 'rounding_mode' => 'half_up',
            'allow_negative' => false, 'finality_contract_ref' => 'source-finality', 'finality_contract_version' => 1,
            'identity_key_version' => $distinct ? '1' : null, 'identity_namespace' => $distinct ? 'host-members/1' : null,
            'cross_source_identity_policy' => 'shared',
        ]);
    }

    protected function completeness(string $status = 'complete', int $sequence = 1, ?string $repair = null): void
    {
        $window = $this->window();
        $this->application()->make(SourceCompletenessIntake::class)->record(new RecordSourceCompleteness(
            sellerRef: $window->sellerRef, environment: $window->environment,
            evidence: AggregationVectors::evidence([
                'src' => 'tariffler', 'seq' => $sequence, 'status' => $status,
                'range' => [(int) bcdiv((string) $window->startMicros, '1000000', 0), (int) bcdiv((string) $window->endMicros, '1000000', 0)],
                'watermark' => (int) bcdiv((string) $window->endMicros, '1000000', 0) + 300, 'repair' => $repair,
            ]),
        ));
    }

    /** @return array<string, mixed> */
    protected function freeze(?string $expected = null): array
    {
        return $this->aggregation()->freeze(new FreezeAggregate(window: $this->window(), expectedHeadDigest: $expected))->body();
    }

    protected function domainRefusal(callable $body, string $code): void
    {
        try {
            $body();
            self::fail('Expected '.$code);
        } catch (ProblemException $exception) {
            self::assertStringStartsWith('billium.usage.'.$code, $exception->detail);
        }
    }

    protected static function micros(string $value): int
    {
        return ((int) (new DateTimeImmutable($value))->format('U')) * 1_000_000;
    }
}
