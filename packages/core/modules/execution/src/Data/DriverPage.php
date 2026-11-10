<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Attributes\Api;

#[Api]
final readonly class DriverPage
{
    /** @param list<ProviderEvidence> $items */
    public function __construct(public array $items, public ?string $nextCursor, public bool $complete) {}
}
