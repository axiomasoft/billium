<?php

declare(strict_types=1);

namespace Billium\Contracts\Data;

use Billium\Contracts\Enums\ProblemCode;
use Packman\Attributes\Api;

/**
 * A decoded problem object (RFC 9457) whose registry members match the code's entry.
 */
#[Api(since: '1.0')]
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
