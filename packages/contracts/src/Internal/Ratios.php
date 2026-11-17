<?php

declare(strict_types=1);

namespace Billium\Contracts\Internal;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Internal;

/**
 * Validated allocation ratios: non-negative integers as decimal strings, at least one positive (BC-MW-14).
 */
#[Internal]
final class Ratios
{
    /**
     * @param  array<mixed>  $ratios
     * @return list<string>
     *
     * @throws ProblemException billium.validation.invalid
     */
    public static function parse(array $ratios): array
    {
        if ($ratios === [] || ! array_is_list($ratios)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'ratios must be a non-empty list');
        }
        $parsed = [];
        $positive = false;
        foreach ($ratios as $ratio) {
            if (! is_string($ratio) || preg_match('/^(0|[1-9][0-9]*)$/D', $ratio) !== 1) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'a ratio must be a non-negative integer string');
            }
            $positive = $positive || $ratio !== '0';
            $parsed[] = $ratio;
        }
        if (! $positive) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'at least one ratio must be positive');
        }

        return $parsed;
    }
}
