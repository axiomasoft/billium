<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Queries;

use Closure;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;

/**
 * Child tables of a dunning case (steps, notifications, signals, interventions, policy migrations) carry case_ref only
 * as a copy; their indexes lead with (seller_ref, environment, dunning_case_id). Filtering them by case_ref alone makes
 * PostgreSQL scan every child row of the seller. Resolving the case id through the unique case_ref key first keeps a
 * per-case read on the child index, and the copied case_ref stays in the predicate so the result set is unchanged.
 */
#[Internal]
final class CaseRefLookup
{
    /** @return Closure(Builder): void */
    public static function caseId(string $sellerRef, string $environment, string $caseRef): Closure
    {
        return static function (Builder $query) use ($sellerRef, $environment, $caseRef): void {
            $query->select('id')
                ->from('billium_collection_dunning_case')
                ->where('seller_ref', $sellerRef)
                ->where('environment', $environment)
                ->where('case_ref', $caseRef);
        };
    }
}
