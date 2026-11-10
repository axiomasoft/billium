<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;

final class ScheduleProblems
{
    public static function refuse(string $reason, ProblemCode $problem = ProblemCode::PolicyUnknown): never
    {
        throw new ProblemException(problem: $problem, detail: 'billium.schedule.'.$reason);
    }
}
