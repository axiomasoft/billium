<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal;

use LogicException;
use Packman\Attributes\Internal;

/** Strict hydration of owned persisted evidence; never coerce malformed commercial terms. */
#[Internal]
final class Payload
{
    /** @param array<string, mixed> $value
     * @param  list<string>  $expected
     */
    public static function fields(array $value, array $expected): void
    {
        if (array_diff(array_keys($value), $expected) !== [] || array_diff($expected, array_keys($value)) !== []) {
            ScheduleProblems::refuse('unsupported_terms_fields');
        }
    }

    public static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new LogicException('Schedule evidence requires a string');
    }

    public static function integer(mixed $value): int
    {
        return is_int($value) ? $value : throw new LogicException('Schedule evidence requires an integer');
    }

    public static function boolean(mixed $value): bool
    {
        return is_bool($value) ? $value : throw new LogicException('Schedule evidence requires a boolean');
    }

    /** @return array<string, mixed> */
    public static function map(mixed $value): array
    {
        if (! is_array($value)) {
            throw new LogicException('Schedule evidence requires an object');
        }
        $result = [];
        foreach ($value as $key => $member) {
            if (! is_string($key)) {
                throw new LogicException('Schedule evidence requires object keys');
            }
            $result[$key] = $member;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public static function decode(mixed $value): array
    {
        return self::map(json_decode(self::text($value), true, 64, JSON_THROW_ON_ERROR));
    }
}
