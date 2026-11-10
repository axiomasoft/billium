<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Tax;

use Billium\Contracts\Attributes\Api;
use DateTimeImmutable;

#[Api]
final readonly class TaxCorrectionRequest
{
    public function __construct(
        public string $sellerRef,
        public string $environment,
        public string $parentOccurrenceRef,
        public string $originalTaxSnapshotRef,
        public string $originalTaxSnapshotDigest,
        public DateTimeImmutable $originalTaxPoint,
        public DateTimeImmutable $correctionEffectiveDate,
        public string $reasonCode,
        public string $jurisdiction,
        public string $originalTaxableBaseMinor,
        public string $correctedTaxableBaseMinor,
        public string $inputDigest,
    ) {}
}
