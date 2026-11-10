<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal;

use Billium\Schedule\Contracts\IntervalResolver;
use Billium\Schedule\Data\ResolvedInterval;
use Billium\Schedule\Data\ScheduleRevisionTerms;
use DateTimeImmutable;
use DateTimeZone;

final class CalendarIntervalResolver implements IntervalResolver
{
    public function resolve(ScheduleRevisionTerms $terms, int $ordinal): ResolvedInterval
    {
        if ($ordinal < 0 || $ordinal > 100_000) {
            ScheduleProblems::refuse('ordinal_out_of_range');
        }
        $start = $this->boundary(terms: $terms, ordinal: $ordinal);
        $end = $this->boundary(terms: $terms, ordinal: $ordinal + 1);

        return new ResolvedInterval(
            start: $start['utc'], end: $end['utc'], localStart: $start['requestedLocal'], localEnd: $end['requestedLocal'],
            cutoffAt: Instants::format(Instants::utc($start['utc'])->modify('-'.$terms->policy->cutoffSeconds.' seconds')),
            tzdbVersion: timezone_version_get(),
            evidence: ['schema' => 'billium.schedule.boundaries/1', 'anchorLocal' => $terms->anchorLocal,
                'ordinal' => $ordinal, 'policy' => $terms->policy->toArray(), 'start' => $start, 'end' => $end],
        );
    }

    /** @return array{utc: string, requestedLocal: string, resolvedLocal: string, offsetSeconds: int, disposition: string} */
    private function boundary(ScheduleRevisionTerms $terms, int $ordinal): array
    {
        $year = (int) substr($terms->anchorLocal, 0, 4);
        $month = (int) substr($terms->anchorLocal, 5, 2);
        $day = (int) substr($terms->anchorLocal, 8, 2);
        $months = $ordinal * $terms->policy->intervalCount * ($terms->policy->frequency === 'year' ? 12 : 1);
        $monthIndex = ($year * 12) + $month - 1 + $months;
        $year = intdiv($monthIndex, 12);
        $month = $monthIndex % 12 + 1;
        if ($year < 1 || $year > 9999) {
            ScheduleProblems::refuse('calendar_range_exceeded');
        }
        $first = new DateTimeImmutable(sprintf('%04d-%02d-01T00:00:00', $year, $month), new DateTimeZone('UTC'));
        $last = (int) $first->format('t');
        $day = $terms->policy->endOfMonth ? $last : min($day, $last);
        $local = sprintf('%04d-%02d-%02dT%s', $year, $month, $day, substr($terms->anchorLocal, 11));
        $naive = new DateTimeImmutable($local, new DateTimeZone('UTC'));
        $zone = new DateTimeZone($terms->policy->timezone);
        $transitions = $zone->getTransitions($naive->getTimestamp() - 259200, $naive->getTimestamp() + 259200);
        $candidates = [];
        foreach (array_unique(array_column($transitions, 'offset')) as $offset) {
            $utc = $naive->getTimestamp() - $offset;
            if (new DateTimeImmutable('@'.$utc)->setTimezone($zone)->format('Y-m-d\TH:i:s') === $local) {
                $candidates[$utc] = $offset;
            }
        }
        ksort($candidates, SORT_NUMERIC);
        if ($candidates !== []) {
            $utc = $terms->policy->dstFoldPolicy === 'later_offset' ? array_key_last($candidates) : array_key_first($candidates);

            return ['utc' => Instants::format(new DateTimeImmutable('@'.$utc)), 'requestedLocal' => $local,
                'resolvedLocal' => $local, 'offsetSeconds' => $candidates[$utc],
                'disposition' => count($candidates) > 1 ? $terms->policy->dstFoldPolicy : 'exact'];
        }
        if ($terms->policy->dstGapPolicy === 'reject') {
            ScheduleProblems::refuse('skipped_local_time');
        }
        $previousOffset = $transitions[0]['offset'];
        foreach ($transitions as $transition) {
            $offset = $transition['offset'];
            $timestamp = $transition['ts'];
            if ($offset > $previousOffset && $naive->getTimestamp() >= $timestamp + $previousOffset
                && $naive->getTimestamp() < $timestamp + $offset) {
                $utc = $naive->getTimestamp() - $previousOffset;

                return ['utc' => Instants::format(new DateTimeImmutable('@'.$utc)), 'requestedLocal' => $local,
                    'resolvedLocal' => new DateTimeImmutable('@'.$utc)->setTimezone($zone)->format('Y-m-d\TH:i:s'),
                    'offsetSeconds' => $offset, 'disposition' => 'shift_forward'];
            }
            $previousOffset = $offset;
        }
        ScheduleProblems::refuse('unresolved_local_boundary');
    }
}
