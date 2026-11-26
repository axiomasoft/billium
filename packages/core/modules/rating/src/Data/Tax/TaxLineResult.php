<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Tax;

use Packman\Attributes\Api;

#[Api]
final readonly class TaxLineResult
{
    public function __construct(
        public string $lineKey,
        public string $netMinor,
        public string $taxMinor,
        public string $grossMinor,
        public string $residualMinor,
    ) {}
}
