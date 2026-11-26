<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Rating;

use Packman\Attributes\Api;

#[Api]
final readonly class Quote
{
    public function __construct(
        public bool $financial,
        public string $inputsDigest,
        public string $netMinor,
        public string $taxMinor,
        public string $grossMinor,
        public string $quoteDigest,
    ) {}
}
