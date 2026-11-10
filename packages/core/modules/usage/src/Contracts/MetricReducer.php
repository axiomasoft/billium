<?php

declare(strict_types=1);

namespace Billium\Usage\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Usage\Data\AggregationPolicy;
use Billium\Usage\Data\FactRevision;
use Billium\Usage\Data\MetricReduction;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Enums\AggregationSemantics;

/** Trusted, pure computation over immutable normalized inputs; no persistence capability (D13/D56). */
#[Api]
#[Spi(SpiKind::Implement)]
interface MetricReducer
{
    public function algorithmId(): string;

    public function version(): int;

    public function semantics(): AggregationSemantics;

    /** @param list<FactRevision> $effective */
    public function reduce(AggregationPolicy $policy, WindowSelector $window, array $effective): MetricReduction;
}
