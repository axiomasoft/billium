<?php

declare(strict_types=1);

namespace Billium\Usage\Contracts;

use Billium\Usage\Data\AggregationPolicy;
use Billium\Usage\Data\FactRevision;
use Billium\Usage\Data\MetricReduction;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Enums\AggregationSemantics;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Trusted, pure computation over immutable normalized inputs; no persistence capability (D13/D56). */
#[Spi(SpiKind::Implement)]
interface MetricReducer
{
    public function algorithmId(): string;

    public function version(): int;

    public function semantics(): AggregationSemantics;

    /** @param list<FactRevision> $effective */
    public function reduce(AggregationPolicy $policy, WindowSelector $window, array $effective): MetricReduction;
}
