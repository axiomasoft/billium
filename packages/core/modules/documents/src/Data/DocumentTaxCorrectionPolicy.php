<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Billium\Documents\Enums\TaxCorrectionTreatment;
use Packman\Attributes\Api;

/** Host-supplied versioned tax correction policy snapshot evaluated outside database transactions. */
#[Api(since: '1.0')]
final readonly class DocumentTaxCorrectionPolicy
{
    public function __construct(
        public string $policyRef,
        public string $policyVersion,
        public TaxCorrectionTreatment $treatment,
        public string $selectedRateDecimal,
        public string $taxPoint,
        public string $roundingPolicyVersion,
        public string $inputDigest,
        public string $outputDigest,
        public string $resolverVersion,
    ) {}
}
