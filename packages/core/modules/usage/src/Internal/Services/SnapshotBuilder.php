<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Services;

use Billium\Usage\Contracts\MetricReducerRegistry;
use Billium\Usage\Data\AggregateSnapshot;
use Billium\Usage\Data\AggregationPolicy;
use Billium\Usage\Data\CompletenessFrontier;
use Billium\Usage\Data\FactRevision;
use Billium\Usage\Data\SourceCompleteness;
use Billium\Usage\Data\SourceFinalityContract;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Enums\AggregationSemantics;
use Billium\Usage\Enums\Reproducibility;
use Billium\Usage\Internal\Canonical\CanonicalJson;
use Billium\Usage\Internal\Errors\UsageProblems;
use Billium\Usage\Internal\Math\Decimal;
use Billium\Usage\Internal\Reducers\DistinctReducer;
use Billium\Usage\Internal\Reducers\EffectiveRevisionSelector;
use Packman\Attributes\Internal;

#[Internal]
final readonly class SnapshotBuilder
{
    public function __construct(private MetricReducerRegistry $registry) {}

    /**
     * @param  list<FactRevision>  $revisions
     * @return list<FactRevision>
     */
    public function effective(AggregationPolicy $policy, WindowSelector $window, array $revisions): array
    {
        return EffectiveRevisionSelector::select(
            revisions: $revisions, startMicros: $policy->semantics === AggregationSemantics::Gauge ? PHP_INT_MIN : $window->startMicros,
            endMicros: $window->endMicros,
        );
    }

    /**
     * @param  list<FactRevision>  $revisions
     * @param  list<SourceCompleteness>  $evidence
     */
    public function build(
        AggregationPolicy $policy, SourceFinalityContract $contract, WindowSelector $window,
        array $revisions, array $evidence, int $headRevision = 0, ?string $headDigest = null,
    ): AggregateSnapshot {
        if ($policy->metricRef !== $window->metricRef || $policy->metricVersion !== $window->metricVersion
            || $policy->finalityContractRef !== $contract->contractRef || $policy->finalityContractVersion !== $contract->version) {
            throw UsageProblems::policyUnknown('window/metric/finality identity mismatch');
        }
        $this->registry->validate($policy);
        $effective = $this->effective(policy: $policy, window: $window, revisions: $revisions);
        if (! $policy->allowNegative) {
            foreach ($effective as $fact) {
                if (bccomp($fact->quantityUnscaled, '0', 0) < 0) {
                    throw UsageProblems::error('negative_quantity');
                }
            }
        }
        $reducer = $this->registry->resolve(algorithmId: $policy->algorithmId, version: $policy->algorithmVersion);
        $result = $reducer->reduce(policy: $policy, window: $window, effective: $effective);
        $unscaled = $result->unscaled;
        if (preg_match('/^-?[0-9]+$/D', $unscaled) !== 1 || (! $policy->allowNegative && bccomp($unscaled, '0', 0) < 0)
            || (($policy->semantics === AggregationSemantics::DistinctExact) !== ($result->memberSetDigest !== null))) {
            throw UsageProblems::error('metric_semantics_unsupported');
        }
        $distinct = $result->memberSetDigest === null ? null : ['count' => $unscaled, 'digest' => $result->memberSetDigest];
        if ($distinct !== null) {
            $union = (new DistinctReducer)->reduce($effective);
            if ($union['count'] !== $unscaled || $union['digest'] !== $result->memberSetDigest) {
                throw UsageProblems::error('metric_semantics_unsupported');
            }
        }
        $absolute = bccomp($unscaled, '0', 0) < 0 ? bcsub('0', $unscaled, 0) : $unscaled;
        $limit = str_repeat('9', 26 + $policy->quantityScale);
        assert(is_numeric($limit));
        if (bccomp($absolute, $limit, 0) > 0) {
            throw UsageProblems::error('quantity_out_of_range');
        }
        $quantity = Decimal::format(unscaled: $unscaled, scale: $policy->quantityScale);
        $members = array_map(static fn (FactRevision $fact): array => [
            'factId' => $fact->factId, 'factRevision' => $fact->factRevision, 'factDigest' => $fact->factDigest,
        ], $effective);
        $frontier = new CompletenessFrontier(contractRef: $contract->contractRef, contractVersion: $contract->version, evidence: $evidence);
        // Scope is part of the group identity, including for an empty, qualified window.
        $payload = [
            'algorithm' => ['id' => $policy->algorithmId, 'version' => $policy->algorithmVersion],
            'metric' => ['ref' => $policy->metricRef, 'version' => $policy->metricVersion, 'definitionDigest' => $policy->metricDefinitionDigest],
            'group' => ['sellerRef' => $window->sellerRef, 'environment' => $window->environment, 'dimensions' => (object) $window->groupKey],
            'window' => ['startMicros' => $window->startMicros, 'endMicros' => $window->endMicros],
            'quantity' => ['value' => $quantity, 'scale' => $policy->quantityScale],
            'members' => $members,
            'distinct' => $distinct === null ? null : ['count' => (int) $distinct['count'], 'memberSetDigest' => $distinct['digest']],
            'completeness' => ['contractRef' => $contract->contractRef, 'contractVersion' => $contract->version, 'frontierDigest' => $frontier->digest],
            'reproducibility' => Reproducibility::Reproducible->value,
        ];
        $digest = CanonicalJson::digest($payload);

        return new AggregateSnapshot(
            snapshotRef: 'agg_'.substr($digest, 7, 40), snapshotDigest: $digest,
            snapshotRevision: $headRevision + 1, predecessorDigest: $headDigest, window: $window,
            algorithmId: $policy->algorithmId, algorithmVersion: $policy->algorithmVersion,
            quantity: $quantity, quantityScale: $policy->quantityScale, effectiveRevisions: $members,
            distinctCount: $distinct === null ? null : (int) $distinct['count'], memberSetDigest: $distinct['digest'] ?? null,
            reproducibility: Reproducibility::Reproducible, completeness: $frontier,
        );
    }
}
