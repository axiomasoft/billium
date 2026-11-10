<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Attributes\Api;

#[Api]
final readonly class Ambiguous
{
    public function __construct(public string $reason) {}
}
