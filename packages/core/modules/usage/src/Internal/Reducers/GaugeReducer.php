<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Reducers;

use Billium\Usage\Data\AggregationPolicy;
use Billium\Usage\Data\FactRevision;
use Billium\Usage\Enums\GaugePolicy;
use Billium\Usage\Enums\MissingInitialPolicy;
use Billium\Usage\Internal\Errors\UsageProblems;
use Billium\Usage\Internal\Math\IntegerRounding;

final class GaugeReducer
{
    /**
     * @param  list<FactRevision>  $allEffective
     * @return numeric-string
     */
    public function reduce(AggregationPolicy $policy, int $start, int $end, array $allEffective): string
    {
        $selection = $this->samples(policy: $policy, start: $start, end: $end, allEffective: $allEffective);
        $in = $selection['in'];
        $carry = $selection['carry'];
        $zero = $policy->missingInitial === MissingInitialPolicy::DeclaredZero;
        if ($policy->gaugePolicy === GaugePolicy::LatestAsOf) {
            return $in !== [] ? $in[array_key_last($in)]->quantityUnscaled
                : ($carry->quantityUnscaled ?? ($zero ? '0' : throw UsageProblems::error('gauge_missing_initial')));
        }
        if ($policy->gaugePolicy === GaugePolicy::Max) {
            $values = array_map(static fn (FactRevision $f): string => $f->quantityUnscaled, $in);
            if ($carry !== null) {
                $values[] = $carry->quantityUnscaled;
            }
            if ($values === []) {
                return $zero ? '0' : throw UsageProblems::error('gauge_missing_initial');
            }
            $max = $values[0];
            foreach ($values as $value) {
                if (bccomp($value, $max, 0) > 0) {
                    $max = $value;
                }
            }

            return $max;
        }
        $timeline = [];
        if ($carry !== null) {
            $timeline[] = [$start, $carry->quantityUnscaled];
        }
        foreach ($in as $fact) {
            $timeline[] = [$fact->observedAtMicros, $fact->quantityUnscaled];
        }
        if ($timeline === [] || $timeline[0][0] > $start) {
            if (! $zero) {
                throw UsageProblems::error('gauge_missing_initial');
            }
            array_unshift($timeline, [$start, '0']);
        }
        $sum = '0';
        foreach ($timeline as $i => [$time, $value]) {
            $next = $timeline[$i + 1][0] ?? $end;
            $sum = bcadd($sum, bcmul($value, (string) ($next - $time), 0), 0);
        }

        return IntegerRounding::divide(
            num: $sum, den: (string) (($policy->timeWeightUnitSeconds ?? throw UsageProblems::policyUnknown()) * 1_000_000),
            mode: $policy->rounding->value,
        );
    }

    /** @param list<FactRevision> $allEffective
     * @return array{in:list<FactRevision>, carry:?FactRevision, carried:bool, declaredZero:bool}
     */
    public function samples(AggregationPolicy $policy, int $start, int $end, array $allEffective): array
    {
        usort($allEffective, static fn (FactRevision $a, FactRevision $b): int => $a->observedAtMicros <=> $b->observedAtMicros
            ?: strcmp($a->sourceRef, $b->sourceRef)
            ?: ($a->sourceSequence ?? -1) <=> ($b->sourceSequence ?? -1)
            ?: strcmp($a->factId, $b->factId));
        $in = [];
        $carry = null;
        foreach ($allEffective as $fact) {
            if ($fact->observedAtMicros >= $end) {
                continue;
            }
            if ($fact->observedAtMicros < $start) {
                $carry = $fact;

                continue;
            }
            $in[] = $fact;
        }
        if ($carry !== null && (! $policy->carryForward
            || ($policy->maxCarrySeconds !== null && $start - $carry->observedAtMicros > $policy->maxCarrySeconds * 1_000_000))) {
            $carry = null;
        }

        return [
            'in' => $in, 'carry' => $carry, 'carried' => $carry !== null,
            'declaredZero' => $carry === null && ($in === [] || $in[0]->observedAtMicros > $start)
                && $policy->missingInitial === MissingInitialPolicy::DeclaredZero,
        ];
    }
}
