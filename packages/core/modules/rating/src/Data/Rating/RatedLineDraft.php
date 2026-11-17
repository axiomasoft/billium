<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Rating;

use Packman\Attributes\Api;

#[Api]
final readonly class RatedLineDraft
{
    public string $metricVersion;

    public function __construct(
        public int $lineNo,
        public string $componentRef,
        public string $metricRef,
        int|string $metricVersion,
        public string $priceRef,
        public int $priceVersion,
        public string $strategyId,
        public int $strategyVersion,
        public string $termsDigest,
        public string $aggregateSnapshotDigest,
        public int $windowStartMicros,
        public int $windowEndMicros,
        public string $quantity,
        public int $quantityScale,
        public string $rawAmount,
        public string $commitmentAdjustment,
        public string $pricedAmount,
        public string $roundingStage,
        public string $roundingMode,
        public string $roundingPolicyVersion,
        public string $exponentVersion,
        public string $taxSnapshotRef,
        public string $jurisdiction,
        public string $taxCode,
        public string $taxMode,
        public string $taxRate,
        public string $netMinor,
        public string $taxMinor,
        public string $grossMinor,
        public string $residualMinor,
        public string $intermediatesJson,
        public ?string $predecessorBasisRef,
        public ?string $commitmentScopeDigest = null,
    ) {
        $this->metricVersion = (string) $metricVersion;
    }
}
