<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Corrections;

use Billium\Rating\Data\Rating\FrozenAggregate;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Data\Rating\FrozenTax;
use Billium\Rating\Data\Tax\TaxSnapshot;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Packman\Attributes\Internal;

#[Internal]
final class CorrectionInputs
{
    /**
     * @param  list<FrozenAggregate>  $aggregates
     * @param  list<TaxSnapshot>  $taxes
     * @param  array{elapsed: string, total: string, amendmentRef: string, revision: int, effectiveAt: string, policy: string, timezone: ?string}|null  $proration
     * @param  array<string, mixed>|null  $correctionEvidence
     */
    public static function replace(FrozenRatingInputs $old, array $aggregates, array $taxes, string $sourceDigest, int $version, string $frontierDigest, ?array $proration = null, ?array $correctionEvidence = null): FrozenRatingInputs
    {
        $frozenTaxes = array_map(static fn (TaxSnapshot $tax): FrozenTax => new FrozenTax(snapshotRef: $tax->snapshotRef,
            snapshotDigest: CanonicalJson::digest($tax), inputDigest: $tax->inputDigest, jurisdiction: $tax->jurisdiction,
            taxCode: $tax->taxCode, mode: $tax->mode->value, rate: $tax->rate, snapshot: $tax), $taxes);

        return new FrozenRatingInputs(sellerRef: $old->sellerRef, environment: $old->environment,
            billingAccountRef: $old->billingAccountRef, occurrenceRef: $old->occurrenceRef,
            periodStartMicros: $old->periodStartMicros, periodEndMicros: $old->periodEndMicros,
            attribution: $old->attribution, agreementRevision: $old->agreementRevision, currency: $old->currency,
            currencyExponent: $old->currencyExponent, aggregates: $aggregates, prices: $old->prices, taxes: $frozenTaxes,
            occurrenceInputsDigest: $old->occurrenceInputsDigest, sourceDigest: $sourceDigest,
            frontierExpectedDigest: $frontierDigest, frontierExpectedRowVersion: $version,
            proration: $proration ?? $old->proration, correctionEvidence: $correctionEvidence);
    }
}
