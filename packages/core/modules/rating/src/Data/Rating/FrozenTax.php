<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Rating;

use Billium\Rating\Data\Tax\TaxSnapshot;
use Packman\Attributes\Api;

#[Api]
final readonly class FrozenTax
{
    public function __construct(
        public string $snapshotRef,
        public string $snapshotDigest,
        public string $inputDigest,
        public string $jurisdiction,
        public string $taxCode,
        public string $mode,
        public string $rate,
        public TaxSnapshot $snapshot,
    ) {}
}
