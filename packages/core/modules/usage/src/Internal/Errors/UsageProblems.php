<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Errors;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Internal;

#[Internal]
final class UsageProblems
{
    /** Domain detail retains the P4.3 code without widening the frozen wire enum (D63). */
    public static function error(string $slug): ProblemException
    {
        return new ProblemException(problem: ProblemCode::UsageConflict, detail: 'billium.usage.'.$slug);
    }

    public static function policyUnknown(string $detail = ''): ProblemException
    {
        return self::error('policy_unknown'.($detail === '' ? '' : ': '.$detail));
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $repairRefs
     */
    public static function windowHeld(array $reasons, array $repairRefs): ProblemException
    {
        return new ProblemException(
            problem: ProblemCode::UsageConflict,
            detail: 'billium.usage.window_held '.json_encode(['reasons' => $reasons, 'repairOrReplayRefs' => $repairRefs], JSON_THROW_ON_ERROR),
        );
    }
}
