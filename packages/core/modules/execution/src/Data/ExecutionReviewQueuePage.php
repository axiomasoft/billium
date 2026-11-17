<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ExecutionReviewQueuePage
{
    /** @param list<ExecutionReviewQueueRow> $rows */
    public function __construct(public array $rows, public ?string $nextCursor) {}
}
