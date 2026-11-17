<?php

declare(strict_types=1);

namespace Billium\Usage\Data;

use Packman\Attributes\Api;

#[Api]
final readonly class MetricReduction
{
    /** @param numeric-string $unscaled */
    public function __construct(public string $unscaled, public ?string $memberSetDigest = null) {}
}
