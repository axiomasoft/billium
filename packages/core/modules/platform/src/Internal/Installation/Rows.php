<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Installation;

use LogicException;
use Packman\Attributes\Internal;

/** Narrowing of database row values. */
#[Internal]
final class Rows
{
    public static function int(mixed $value): int
    {
        return is_int($value) ? $value : (is_string($value) && is_numeric($value) ? (int) $value : throw new LogicException('Expected an integer column'));
    }

    public static function string(mixed $value): string
    {
        return is_string($value) ? $value : throw new LogicException('Expected a text column');
    }

    /** A column of a query-builder row (stdClass). */
    public static function field(mixed $row, string $column): mixed
    {
        return is_object($row) ? (get_object_vars($row)[$column] ?? null) : throw new LogicException('Expected a row object');
    }

    public static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : self::string($value);
    }
}
