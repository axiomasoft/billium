<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Enums\ProblemCode;

/** Authentication refusal is not financial evidence or a provider decline (D31). */
#[Api]
final readonly class RejectedCallback
{
    public ProblemCode $problem;

    public function __construct(public string $reason)
    {
        $this->problem = ProblemCode::AuthDenied;
    }
}
