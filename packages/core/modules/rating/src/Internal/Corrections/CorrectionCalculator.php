<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Corrections;

use Billium\Rating\Data\Correction\CorrectionComputation;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Data\Rating\RatedBasisDraft;
use Billium\Rating\Data\Tax\TaxCorrectionPolicySnapshot;
use Billium\Rating\Data\Tax\TaxCorrectionRequest;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Packman\Attributes\Internal;

#[Internal]
final readonly class CorrectionCalculator
{
    public function __construct(private RatingComputation $rating) {}

    public function compute(FrozenRatingInputs $inputs, RatedBasisDraft $predecessor, int $revision,
        TaxCorrectionPolicySnapshot $policy, TaxCorrectionRequest $request): CorrectionComputation
    {
        $draft = $this->rating->compute(inputs: $inputs, proration: $inputs->proration,
            predecessorBasisRef: $predecessor->basisRef, correctionRevision: $revision);
        $delta = DeltaCalculator::delta(lastEffective: ['net' => $predecessor->netMinor, 'tax' => $predecessor->taxMinor, 'gross' => $predecessor->grossMinor],
            corrected: ['net' => $draft->netMinor, 'tax' => $draft->taxMinor, 'gross' => $draft->grossMinor]);
        $ref = 'corr_'.substr(hash('sha256', $inputs->occurrenceRef.'#'.$revision), 0, 48);
        $digest = CanonicalJson::digest([$inputs, $predecessor, $revision, $policy, $request, $draft, $delta]);

        return new CorrectionComputation(correctionRef: $ref, correctionRevision: $revision,
            predecessorBasisRef: $predecessor->basisRef, sourceFrontierDigest: $inputs->sourceDigest,
            correctedBasis: $draft, deltaNetMinor: $delta['net'], deltaTaxMinor: $delta['tax'],
            deltaGrossMinor: $delta['gross'], outputDigest: $digest);
    }
}
