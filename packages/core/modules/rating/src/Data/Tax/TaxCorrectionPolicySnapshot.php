<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Tax;

use Billium\Contracts\Attributes\Api;
use DateTimeImmutable;

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
