<?php

declare(strict_types=1);

namespace Billium\Schedule\Data;

use Billium\Contracts\Attributes\Api;

#[Api]
final readonly class DueBatch
{
    /** @param list<array<string, mixed>> $schedules */
    public function __construct(public array $schedules, public ?string $cursor) {}
}
