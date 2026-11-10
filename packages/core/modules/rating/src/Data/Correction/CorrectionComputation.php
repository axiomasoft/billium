<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Correction;

use Billium\Contracts\Attributes\Api;
use Billium\Rating\Data\Rating\RatedBasisDraft;

#[Api]
final readonly class CorrectionComputation
{
    public function __construct(
        public string $correctionRef,
        public int $correctionRevision,
        public string $predecessorBasisRef,
        public string $sourceFrontierDigest,
        public RatedBasisDraft $correctedBasis,
        public string $deltaNetMinor,
        public string $deltaTaxMinor,
        public string $deltaGrossMinor,
        public string $outputDigest,
    ) {}
}
