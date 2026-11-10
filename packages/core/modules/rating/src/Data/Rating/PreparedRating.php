<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Rating;

use Billium\Contracts\Attributes\Api;

#[Api]
final readonly class PreparedRating
{
    public function __construct(
        public FrozenRatingInputs $inputs,
        public RatedBasisDraft $draft,
    ) {}
}
