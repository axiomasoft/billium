<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Adapters;

use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Rating\Data\Rating\FrozenPrice;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Money\MoneyFactory;
use Billium\Rating\Internal\Storage\RatingRows;
use Packman\Attributes\Internal;

/** Q1=a: ledger owns gross debt; immutable rating lines own net/tax and source provenance. */
#[Internal]
final class ObligationCommandMapper
{
    public function map(PreparedRating $prepared): RecordObligation
    {
        $in = $prepared->inputs;
        $ref = 'rating:'.$prepared->draft->basisRef;

        return self::command(in: $in, commandKey: $ref, basisRef: $ref, grossMinor: $prepared->draft->grossMinor, compositeRef: $ref,
            taxSnapshotRef: count($in->taxes) === 1 ? $in->taxes[0]->snapshotRef : $ref);
    }

    /** Positive correction delta booked as a new obligation on a basis derived from the original basis and the correction identity. */
    public function mapCorrection(FrozenRatingInputs $in, string $correctionRef, string $basisRef, string $deltaGrossMinor,
        string $correctedBasisRef, string $taxSnapshotRef): RecordObligation
    {
        return self::command(in: $in, commandKey: 'rating:'.$correctionRef, basisRef: $basisRef, grossMinor: $deltaGrossMinor,
            compositeRef: 'rating:'.$correctedBasisRef, taxSnapshotRef: $taxSnapshotRef);
    }

    public static function scope(FrozenRatingInputs $in): BillingAccountScope
    {
        return new BillingAccountScope(sellerRef: $in->sellerRef, environment: $in->environment, billingAccountRef: $in->billingAccountRef);
    }

    /** A single priced component names its own price version; several components are identified by the rated basis. */
    private static function command(FrozenRatingInputs $in, string $commandKey, string $basisRef, string $grossMinor,
        string $compositeRef, string $taxSnapshotRef): RecordObligation
    {
        $attribution = RatingRows::map(json_decode($in->attribution, true, 32, JSON_THROW_ON_ERROR));
        $first = $in->prices[0];
        $single = count($in->prices) === 1;

        return new RecordObligation(commandKey: $commandKey, scope: self::scope($in),
            obligationRef: $commandKey, basisRef: $basisRef,
            customerRef: RatingRows::text($attribution['customerRef'] ?? null), componentRef: $first->componentRef,
            money: MoneyFactory::fromMinor(minor: $grossMinor, currency: $in->currency, exponent: $in->currencyExponent),
            termsDigest: $single ? $first->termsDigest : CanonicalJson::digest(array_map(static fn (FrozenPrice $p): array => [$p->componentRef, $p->termsDigest], $in->prices)),
            priceVersionRef: $single ? $first->priceRef.'@'.$first->priceVersion : $compositeRef,
            taxSnapshotRef: $taxSnapshotRef);
    }
}
