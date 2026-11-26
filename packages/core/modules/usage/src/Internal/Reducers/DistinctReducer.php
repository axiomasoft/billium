<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Reducers;

use Billium\Usage\Data\FactRevision;
use Billium\Usage\Internal\Errors\UsageProblems;
use Packman\Attributes\Internal;

#[Internal]
final class DistinctReducer
{
    /**
     * @param  list<FactRevision>  $effective
     * @return array{count:numeric-string, digest:string, members:list<string>}
     */
    public function reduce(array $effective): array
    {
        $members = [];
        foreach ($effective as $fact) {
            if ($fact->memberIds === [] && $fact->quantityUnscaled !== '0') {
                throw UsageProblems::error('metric_semantics_unsupported');
            }
            foreach ($fact->memberIds as $member) {
                if (preg_match('/^[0-9a-f]{64}$/D', $member) !== 1) {
                    throw UsageProblems::error('invalid_member');
                }
                // Prefix avoids PHP coercing digit-only opaque identities to integer array keys.
                $members['m:'.$member] = $member;
            }
        }
        $ids = array_values($members);
        sort($ids, SORT_STRING);

        return ['count' => (string) count($ids), 'digest' => 'sha256:'.hash('sha256', implode("\n", $ids)), 'members' => $ids];
    }
}
