<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Storage;

use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final class RatingRows
{
    public static function nullableText(mixed $value): ?string
    {
        return $value === null ? null : self::text($value);
    }

    /** @return numeric-string */
    public static function decimal(mixed $value): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || ! is_numeric($value) || preg_match('/^-?(0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) !== 1) {
            throw new LogicException('Invalid stored exact rating number');
        }

        return $value;
    }

    /** @return list<string> */
    public static function texts(mixed $value): array
    {
        return array_map(self::text(...), self::items($value));
    }

    public static function text(mixed $value): string
    {
        if (! is_string($value)) {
            throw new LogicException('Invalid stored rating text');
        }

        return $value;
    }

    public static function integer(mixed $value): int
    {
        if (! is_int($value) && (! is_string($value) || preg_match('/^-?[0-9]+$/D', $value) !== 1)) {
            throw new LogicException('Invalid stored rating integer');
        }

        return (int) $value;
    }

    public static function nullableInteger(mixed $value): ?int
    {
        return $value === null ? null : self::integer($value);
    }

    public static function boolean(mixed $value): bool
    {
        if (! is_bool($value)) {
            throw new LogicException('Invalid stored rating boolean');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public static function map(mixed $value): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new LogicException('Invalid stored rating object');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new LogicException('Invalid stored rating key');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return list<mixed> */
    public static function items(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new LogicException('Invalid stored rating list');
        }

        return $value;
    }
}
