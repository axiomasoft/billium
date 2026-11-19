<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class DocumentTaxLineSnapshot
{
    public function __construct(
        public string $lineRef,
        public string $taxCode,
        public string $rateDecimal,
        public int $netMinor,
        public int $taxMinor,
        public int $grossMinor,
    ) {}
}
