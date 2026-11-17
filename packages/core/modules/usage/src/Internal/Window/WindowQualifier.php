<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Window;

use Billium\Usage\Data\SourceCompleteness;
use Billium\Usage\Data\WindowQualification;
use Billium\Usage\Enums\CompletenessStatus;
use Billium\Usage\Internal\Errors\UsageProblems;
use Packman\Attributes\Internal;

#[Internal]
final class WindowQualifier
{
    /**
     * @param  list<string>  $requiredSources
     * @param  list<SourceCompleteness>  $evidence
     */
    public function qualify(int $start, int $end, array $requiredSources, int $graceMicros, array $evidence): WindowQualification
    {
        if ($requiredSources === [] || $graceMicros < 0 || $end > PHP_INT_MAX - $graceMicros) {
            throw UsageProblems::policyUnknown('invalid required sources or grace');
        }
        sort($requiredSources, SORT_STRING);
        $reasons = [];
        $repairs = [];
        foreach (array_unique($requiredSources) as $source) {
            $rows = [];
            foreach ($evidence as $item) {
                if ($item->sourceRef !== $source || $item->rangeStartMicros >= $end || $item->rangeEndMicros <= $start) {
                    continue;
                }
                if (isset($rows[$item->evidenceSeq]) && $rows[$item->evidenceSeq]->evidenceDigest !== $item->evidenceDigest) {
                    throw UsageProblems::error('completeness_conflict');
                }
                $rows[$item->evidenceSeq] = $item;
            }
            ksort($rows, SORT_NUMERIC);
            $points = [$start, $end];
            $watermark = null;
            foreach ($rows as $item) {
                foreach ([$item->rangeStartMicros, $item->rangeEndMicros] as $point) {
                    if ($point > $start && $point < $end) {
                        $points[] = $point;
                    }
                }
                if ($item->status === CompletenessStatus::Complete) {
                    $watermark = $watermark === null ? $item->watermarkMicros : max($watermark, $item->watermarkMicros);
                }
            }
            $points = array_values(array_unique($points));
            sort($points, SORT_NUMERIC);
            for ($i = 0; $i + 1 < count($points); $i++) {
                [$a, $b] = [$points[$i], $points[$i + 1]];
                $status = CompletenessStatus::Gap;
                $repair = null;
                foreach ($rows as $item) {
                    if ($item->rangeStartMicros <= $a && $item->rangeEndMicros >= $b) {
                        $status = $item->status;
                        $repair = $item->repairOrReplayRef;
                    }
                }
                if ($status !== CompletenessStatus::Complete) {
                    $reasons[] = "{$source}:{$status->value}@{$a}-{$b}";
                    if ($repair !== null) {
                        $repairs[] = $repair;
                    }
                }
            }
            if ($watermark === null || $watermark < $end + $graceMicros) {
                $reasons[] = "{$source}:watermark_behind";
            }
        }
        sort($reasons, SORT_STRING);
        sort($repairs, SORT_STRING);

        return new WindowQualification(qualified: $reasons === [], reasons: $reasons, repairRefs: array_values(array_unique($repairs)));
    }
}
