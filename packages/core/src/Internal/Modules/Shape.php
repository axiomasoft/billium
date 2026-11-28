<?php

declare(strict_types=1);

namespace Billium\Core\Internal\Modules;

use InvalidArgumentException;
use Packman\Attributes\Internal;

/**
 * Strict reader of decoded JSON: unknown, missing or mistyped members fail loudly, so an incomplete or
 * foreign manifest never reaches money admission (..308).
 */
#[Internal]
final class Shape
{
    /**
     * @param  list<string>  $required
     * @param  list<string>  $optional
     * @return array<string, mixed>
     */
    public static function object(mixed $value, array $required, string $context, array $optional = []): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw self::fail($context, 'must be an object');
        }
        foreach ($required as $key) {
            if (! array_key_exists($key, $value)) {
                throw self::fail($context, "missing member {$key}");
            }
        }
        foreach (array_keys($value) as $key) {
            if (! in_array($key, [...$required, ...$optional], true)) {
                throw self::fail($context, "unknown member {$key}");
            }
        }

        $typed = [];
        foreach ($value as $key => $member) {
            $typed[(string) $key] = $member;
        }

        return $typed;
    }

    /** @param array<string, mixed> $data */
    public static function string(array $data, string $key, string $context): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : throw self::fail($context, "{$key} must be a string");
    }

    /** @param array<string, mixed> $data */
    public static function optionalString(array $data, string $key, string $context): ?string
    {
        return array_key_exists($key, $data) ? self::string($data, $key, $context) : null;
    }

    /** @param array<string, mixed> $data */
    public static function int(array $data, string $key, string $context): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : throw self::fail($context, "{$key} must be an integer");
    }

    /** @param array<string, mixed> $data */
    public static function bool(array $data, string $key, string $context): bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : throw self::fail($context, "{$key} must be a boolean");
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function strings(array $data, string $key, string $context): array
    {
        $value = $data[$key] ?? null;
        if (! is_array($value) || ! array_is_list($value)) {
            throw self::fail($context, "{$key} must be a list");
        }
        $strings = [];
        foreach ($value as $item) {
            $strings[] = is_string($item) ? $item : throw self::fail($context, "{$key} must hold strings");
        }

        return $strings;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<mixed>
     */
    public static function list(array $data, string $key, string $context): array
    {
        $value = $data[$key] ?? null;
        if (! is_array($value) || ! array_is_list($value)) {
            throw self::fail($context, "{$key} must be a list");
        }

        return $value;
    }

    public static function fail(string $context, string $message): InvalidArgumentException
    {
        return new InvalidArgumentException("module.descriptor_invalid: {$context}: {$message}");
    }
}
