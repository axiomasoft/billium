<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Errors;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;

final class RatingProblems
{
    public static function invalid(string $detail): ProblemException
    {
        return new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.terms_invalid: '.$detail);
    }

    public static function unsupported(string $detail): ProblemException
    {
        return new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.unsupported_combination: '.$detail);
    }

    public static function negative(): ProblemException
    {
        return new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.quantity_negative');
    }
}
