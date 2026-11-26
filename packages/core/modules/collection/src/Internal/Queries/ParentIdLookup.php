<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Queries;

use Closure;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;

/**
 * Child tables of a dunning case (steps, notifications, signals, interventions, policy migrations) and of a consent
 * guard (tombstones) carry the parent's public ref only as a copy; their indexes lead with (seller_ref, environment,
 * parent id). Filtering them by the copied ref alone makes PostgreSQL scan every child row of the seller. Resolving
 * the parent id through its unique ref key first keeps a per-parent read on the child index, and the copied ref stays
 * in the predicate so the result set is unchanged.
 */
#[Internal]
final class ParentIdLookup
{
    /** @return Closure(Builder): void */
    public static function dunningCase(string $sellerRef, string $environment, string $caseRef): Closure
    {
        return self::idOf('billium_collection_dunning_case', 'case_ref', $sellerRef, $environment, $caseRef);
    }

    /** @return Closure(Builder): void */
    public static function consentGuard(string $sellerRef, string $environment, string $consentRef): Closure
    {
        return self::idOf('billium_collection_consent_guard', 'consent_ref', $sellerRef, $environment, $consentRef);
    }

    /**
     * @param  'billium_collection_dunning_case'|'billium_collection_consent_guard'  $table
     * @param  'case_ref'|'consent_ref'  $refColumn
     * @return Closure(Builder): void
     */
    private static function idOf(string $table, string $refColumn, string $sellerRef, string $environment, string $ref): Closure
    {
        return static function (Builder $query) use ($table, $refColumn, $sellerRef, $environment, $ref): void {
            $query->select('id')
                ->from($table)
                ->where('seller_ref', $sellerRef)
                ->where('environment', $environment)
                ->where($refColumn, $ref);
        };
    }
}
