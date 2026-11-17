<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal\Errors;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Internal;

#[Internal]
final class OccurrenceProblems
{
    public static function refuse(string $detail, bool $stored = false): never
    {
        throw new ProblemException($stored ? ProblemCode::PolicyUnknown : ProblemCode::ValidationInvalid, 'billium.'.$detail);
    }
}
