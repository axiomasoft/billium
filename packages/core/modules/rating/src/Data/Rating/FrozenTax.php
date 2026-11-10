<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Rating;

use Billium\Contracts\Attributes\Api;
use Billium\Rating\Data\Tax\TaxSnapshot;

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
