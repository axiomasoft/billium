<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Tax;

use Packman\Attributes\Api;

#[Api]
final readonly class TaxResult
{
    /** @param list<TaxLineResult> $lines */
    public function __construct(
        public array $lines,
        public string $netMinor,
        public string $taxMinor,
        public string $grossMinor,
    ) {}
}
