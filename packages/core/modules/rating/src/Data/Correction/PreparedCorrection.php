<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Correction;

use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Data\Tax\TaxCorrectionPolicySnapshot;
use Billium\Rating\Data\Tax\TaxCorrectionRequest;
use Billium\Rating\Enums\ReleasePolicy;
use Packman\Attributes\Api;

#[Api]
final readonly class PreparedCorrection
{
    /** @param list<CorrectionCreditPart> $creditParts */
    public function __construct(
        public FrozenRatingInputs $inputs,
        public CorrectionComputation $computation,
        public TaxCorrectionPolicySnapshot $taxPolicy,
        public TaxCorrectionRequest $taxRequest,
        public int $expectedScheduleHeadRevision,
        public ?string $expectedScheduleHeadFrontierDigest,
        public int $expectedFrontierRowVersion,
        public string $expectedFrontierDigest,
        public ?ReleasePolicy $releasePolicy,
        public PrepareCorrection $command,
        public ?string $originalObligationRef,
        public ?string $invoiceRef,
        public string $originalBasisRef,
        public int $expectedPolicyRowVersion,
        public array $creditParts = [],
    ) {}
}
