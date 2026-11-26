<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Tax;

use Billium\Rating\Enums\TaxMode;
use Billium\Rating\Enums\TaxSnapshotKind;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api]
final readonly class TaxSnapshot
{
    public function __construct(
        public string $snapshotRef,
        public TaxSnapshotKind $kind,
        public TaxMode $mode,
        public string $jurisdiction,
        public string $taxCode,
        public string $rate,
        public DateTimeImmutable $effectiveDate,
        public DateTimeImmutable $taxPoint,
        public ?string $noTaxReason,
        public string $resolver,
        public string $resolverVersion,
        public string $inputDigest,
        public string $evidenceDigest,
    ) {}
}
