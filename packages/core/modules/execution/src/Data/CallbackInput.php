<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Attributes\Api;

#[Api]
final readonly class CallbackInput
{
    /** @param array<string, string> $headers Raw bytes retained by future callback custody, not by this DTO. */
    public function __construct(public ExecutionBinding $binding, public string $body, public array $headers) {}
}
