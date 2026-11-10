<?php

declare(strict_types=1);

namespace Billium\Usage\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Usage\Enums\AggregationSemantics;
use Billium\Usage\Enums\GaugePolicy;
use Billium\Usage\Enums\MissingInitialPolicy;
use Billium\Usage\Enums\RoundingMode;
use Billium\Usage\Internal\Errors\UsageProblems;

#[Api]
final readonly class AggregationPolicy
{
    public string $metricVersion;

    public function __construct(
        public string $metricRef,
        int|string $metricVersion,
        public string $metricDefinitionDigest,
        public AggregationSemantics $semantics,
        public string $algorithmId,
        public int $algorithmVersion,
        public ?GaugePolicy $gaugePolicy,
        public MissingInitialPolicy $missingInitial,
        public bool $carryForward,
        public ?int $maxCarrySeconds,
        public ?int $timeWeightUnitSeconds,
        public int $quantityScale,
        public RoundingMode $rounding,
        public bool $allowNegative,
        public string $finalityContractRef,
        public int $finalityContractVersion,
        public ?string $identityKeyVersion = null,
        public ?string $identityNamespace = null,
        public string $crossSourceIdentityPolicy = 'shared',
    ) {
        if ($metricRef === '' || (string) $metricVersion === '' || $quantityScale < 0 || $quantityScale > 12
            || $algorithmVersion < 1 || $finalityContractVersion < 1 || $finalityContractRef === ''
            || ($semantics === AggregationSemantics::Gauge) !== ($gaugePolicy !== null)
            || ($maxCarrySeconds !== null && ($maxCarrySeconds < 0 || $maxCarrySeconds > 2_147_483_647))
            || ($timeWeightUnitSeconds !== null && ($timeWeightUnitSeconds < 1 || $timeWeightUnitSeconds > 2_147_483_647))
            || ($algorithmId === 'gauge_time_weighted') !== ($timeWeightUnitSeconds !== null)
            || ($semantics === AggregationSemantics::DistinctExact && ($quantityScale !== 0 || $allowNegative))
            || ! in_array($crossSourceIdentityPolicy, ['shared', 'source_scoped'], true)) {
            throw UsageProblems::policyUnknown('invalid aggregation policy');
        }
        $this->metricVersion = (string) $metricVersion;
    }
}
