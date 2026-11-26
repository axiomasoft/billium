<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Tax;

use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api]
final readonly class TaxCorrectionPolicySnapshot
{
    public function __construct(
        public string $policyRef,
        public string $policyVersion,
        public string $treatment,
        public TaxSnapshot $selectedRate,
        public DateTimeImmutable $effectiveFrom,
        public ?DateTimeImmutable $effectiveTo,
        public string $inputDigest,
        public string $outputDigest,
        public string $resolverVersion,
    ) {}
}
