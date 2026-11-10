<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Ledger;

use Billium\Ledger\Data\BillingAccountScope;
use Billium\Ledger\Data\RecordObligation;
use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Money\MoneyFactory;
use Billium\Rating\Internal\Storage\RatingRows;

/** Q1=a: ledger owns gross debt; immutable rating lines own net/tax and source provenance. */
final class ObligationCommandMapper
{
    public function map(PreparedRating $prepared): RecordObligation
    {
        $in = $prepared->inputs;
        $draft = $prepared->draft;
        $attribution = RatingRows::map(json_decode($in->attribution, true, 32, JSON_THROW_ON_ERROR));
        $first = $in->prices[0];

        return new RecordObligation(commandKey: 'rating:'.$draft->basisRef,
            scope: new BillingAccountScope(sellerRef: $in->sellerRef, environment: $in->environment, billingAccountRef: $in->billingAccountRef),
            obligationRef: 'rating:'.$draft->basisRef, basisRef: 'rating:'.$draft->basisRef,
            customerRef: RatingRows::text($attribution['customerRef'] ?? null), componentRef: $first->componentRef,
            money: MoneyFactory::fromMinor(minor: $draft->grossMinor, currency: $in->currency, exponent: $in->currencyExponent),
            termsDigest: count($in->prices) === 1 ? $first->termsDigest : CanonicalJson::digest(array_map(static fn ($p): array => [$p->componentRef, $p->termsDigest], $in->prices)),
            priceVersionRef: count($in->prices) === 1 ? $first->priceRef.'@'.$first->priceVersion : 'rating:'.$draft->basisRef,
            taxSnapshotRef: count($in->taxes) === 1 ? $in->taxes[0]->snapshotRef : 'rating:'.$draft->basisRef);
    }
}
