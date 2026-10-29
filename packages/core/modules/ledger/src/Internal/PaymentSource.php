<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Illuminate\Database\Query\Builder;
use stdClass;

/** Payment selectors share the source identity used by ledger snapshots; bare refs require one match. */
final class PaymentSource
{
    public static function identity(string $authority, string $reference): string
    {
        return 'payment:'.rawurlencode($authority).':'.rawurlencode($reference);
    }

    public static function resolve(Builder $query, string $reference): ?stdClass
    {
        $qualified = explode(':', $reference);
        $query->where(static function (Builder $q) use ($reference, $qualified): void {
            $q->where('payment_ref', $reference);
            if (count($qualified) === 3 && $qualified[0] === 'payment'
                && self::identity(rawurldecode($qualified[1]), rawurldecode($qualified[2])) === $reference) {
                $q->orWhere(static fn (Builder $source): Builder => $source
                    ->where('source_authority', rawurldecode($qualified[1]))->where('payment_ref', rawurldecode($qualified[2])));
            }
        });
        $matches = $query->orderBy('id')->limit(2)->get();
        if ($matches->count() > 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Ambiguous payment reference; use its authority-qualified payment identity');
        }

        return $matches->first();
    }
}
