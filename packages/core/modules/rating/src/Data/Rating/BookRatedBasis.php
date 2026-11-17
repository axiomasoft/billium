<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Rating;

use Packman\Attributes\Api;

#[Api]
final readonly class BookRatedBasis
{
    public function __construct(
        public PreparedRating $prepared,
        public string $operationKey,
    ) {}
}
