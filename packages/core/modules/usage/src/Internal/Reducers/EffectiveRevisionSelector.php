<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Reducers;

use Billium\Usage\Data\FactRevision;
use Billium\Usage\Internal\Errors\UsageProblems;
use Packman\Attributes\Internal;

#[Internal]
final class EffectiveRevisionSelector
{
    /**
     * @param  list<FactRevision>  $revisions
     * @return list<FactRevision>
     */
    public static function select(array $revisions, int $startMicros, int $endMicros): array
    {
        $best = [];
        $seen = [];
        foreach ($revisions as $revision) {
            $identity = $revision->factId.':'.$revision->factRevision;
            if (isset($seen[$identity]) && $seen[$identity] !== $revision->factDigest) {
                throw UsageProblems::error('fact_revision_conflict');
            }
            $seen[$identity] = $revision->factDigest;
            $current = $best[$revision->factId] ?? null;
            if ($current === null || $revision->factRevision > $current->factRevision) {
                $best[$revision->factId] = $revision;
            }
        }
        $effective = array_values(array_filter($best, static fn (FactRevision $r): bool => ! $r->retracted && $r->observedAtMicros >= $startMicros && $r->observedAtMicros < $endMicros));
        usort($effective, static fn (FactRevision $a, FactRevision $b): int => strcmp($a->factId, $b->factId));

        return $effective;
    }
}
