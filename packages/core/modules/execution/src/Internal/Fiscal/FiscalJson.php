<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Fiscal;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

/** Zero-float deterministic JSON helpers for fiscal JSONB storage and canonical digests. */
#[Internal]
final class FiscalJson
{
    /** @param array<string, mixed> $map */
    public static function encodeObject(array $map): string
    {
        if ($map === []) {
            return '{}';
        }
        $normalized = self::normalizeValue($map);
        if (! is_array($normalized) || array_is_list($normalized)) {
            return json_encode(new stdClass, JSON_THROW_ON_ERROR);
        }

        return json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $map */
    public static function digest(array $map): string
    {
        $normalized = self::normalizeValue($map);
        if (! is_array($normalized)) {
            throw new LogicException('Fiscal digest input must normalize to an array');
        }

        return 'sha256:'.hash('sha256', (new Codec)->canonicalize($normalized));
    }

    /** @return array<string, mixed> */
    public static function decodeObject(mixed $raw): array
    {
        if (! is_string($raw)) {
            throw new LogicException('Expected stored fiscal JSON string');
        }
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new LogicException('Expected stored fiscal JSON object');
        }
        if ($decoded === []) {
            return [];
        }
        $result = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                throw new LogicException('Expected string keys in stored fiscal JSON object');
            }
            $result[$key] = $value;
        }

        return $result;
    }

    public static function text(mixed $value): string
    {
        if (! is_string($value)) {
            throw new LogicException('Expected non-null string column');
        }

        return $value;
    }

    public static function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::text($value);
    }

    public static function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            return (int) $value;
        }

        throw new LogicException('Expected integer column');
    }

    public static function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [1, '1', 't', 'true'], true)) {
            return true;
        }
        if (in_array($value, [0, '0', 'f', 'false'], true)) {
            return false;
        }

        throw new LogicException('Expected boolean column');
    }

    public static function timestamp(mixed $value): DateTimeImmutable
    {
        return new DateTimeImmutable(self::text($value));
    }

    public static function nullableTimestamp(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return self::timestamp($value);
    }

    public static function formatDbTimestamp(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    public static function currency(mixed $code, mixed $scale): Currency
    {
        $currencyCode = self::text($code);
        if ($currencyCode === '') {
            throw new LogicException('Expected non-empty currency code');
        }
        $currencyScale = self::integer($scale);
        if ($currencyScale < 0) {
            throw new LogicException('Expected non-negative currency scale');
        }

        return new Currency($currencyCode, $currencyScale);
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new JsonException('Floating point values are forbidden in fiscal storage');
        }
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        }
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            if ($value === []) {
                return [];
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(self::normalizeValue(...), $value);
        }

        return $value;
    }
}
