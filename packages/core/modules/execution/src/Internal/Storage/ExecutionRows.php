<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Storage;

use DateTimeImmutable;
use DateTimeInterface;
use LogicException;
use Packman\Attributes\Internal;

/** Strict coercion of PostgreSQL row values shared by every execution reader and writer. */
#[Internal]
final class ExecutionRows
{
    /** Timestamp literal accepted by timestamptz columns without losing microseconds or the offset. */
    public const string TIMESTAMP = 'Y-m-d H:i:s.uP';

    public static function text(mixed $value): string
    {
        return ExecutionCodec::text($value);
    }

    public static function nullableText(mixed $value): ?string
    {
        return $value === null ? null : self::text($value);
    }

    /** Accepts native bigint values and the decimal strings drivers return for them. */
    public static function integer(mixed $value): int
    {
        return is_int($value) ? $value : (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1 ? (int) $value : throw new LogicException('Invalid stored integer'));
    }

    public static function nullableInteger(mixed $value): ?int
    {
        return $value === null ? null : self::integer($value);
    }

    public static function date(mixed $value): DateTimeImmutable
    {
        return new DateTimeImmutable(self::text($value));
    }

    public static function nullableDate(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : self::date($value);
    }

    /** @return list<mixed> */
    public static function list(string $json): array
    {
        $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($value) || ! array_is_list($value)) {
            throw new LogicException('Stored list required');
        }

        return $value;
    }

    public static function stamp(DateTimeInterface $time): string
    {
        return $time->format(self::TIMESTAMP);
    }

    public static function nullableStamp(?DateTimeInterface $time): ?string
    {
        return $time === null ? null : self::stamp($time);
    }

    /** @param list<string> $items */
    public static function textArray(array $items): string
    {
        return '{'.implode(',', array_map(static fn (string $s): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $s).'"', $items)).'}';
    }
}
