<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Tax;

use Packman\Attributes\Api;

#[Api]
final readonly class TaxLineInput
{
    public function __construct(
        public string $lineKey,
        public string $amount,
    ) {}
}
