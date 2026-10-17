<?php

declare(strict_types=1);

namespace Billium\Contracts\Exceptions;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Enums\ProblemCode;
use RuntimeException;

/**
 * Every refusal of decode and money operations: a registered problem code and a diagnostic detail.
 */
#[Api]
final class ProblemException extends RuntimeException
{
    public function __construct(public readonly ProblemCode $problem, public readonly string $detail = '')
    {
        parent::__construct($detail === '' ? $problem->value : $problem->value.': '.$detail);
    }
}
