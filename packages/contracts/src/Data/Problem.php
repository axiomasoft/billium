<?php

declare(strict_types=1);

namespace Billium\Contracts\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Enums\ProblemCode;

/**
 * A decoded problem object (RFC 9457) whose registry members match the code's entry.
 */
#[Api]
final readonly class Problem
{
    public function __construct(
        public ProblemCode $code,
        public string $title,
        public ?string $detail = null,
        public ?string $type = null,
        public ?int $status = null,
        public ?string $instance = null,
    ) {}
}
