<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Rating;

use Packman\Attributes\Api;

#[Api]
final readonly class QuoteRequest
{
    public function __construct(
        public FrozenRatingInputs $inputs,
    ) {}
}
