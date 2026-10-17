<?php

declare(strict_types=1);

namespace Billium\Contracts\Internal;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use stdClass;

/**
 * RFC 8785 canonical JSON for strings, integers, booleans, null, arrays and objects (D32).
 * Floats and integers outside the exact IEEE 754 range are refused (BC-ID-16).
 */
final class Jcs
{
    private const int MAX_SAFE = 9007199254740991;

    /**
     * @throws ProblemException billium.validation.invalid for a float, an unsafe integer or invalid UTF-8
     */
    public static function canonicalize(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => self::integer($value),
            is_string($value) => self::string($value),
            $value instanceof stdClass => self::object(self::members($value)),
            is_array($value) => array_is_list($value) ? self::list($value) : self::object($value),
            default => throw new ProblemException(ProblemCode::ValidationInvalid, 'a JSON number that is not an integer cannot be canonicalized'),
        };
    }

    private static function integer(int $value): string
    {
        if (abs($value) > self::MAX_SAFE) {
            throw new ProblemException(ProblemCode::ValidationInvalid, "integer {$value} is outside the exact IEEE 754 range");
        }

        return (string) $value;
    }

    private static function string(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'a string is not valid UTF-8');
        }
        $escaped = preg_replace_callback(
            '/[\x00-\x1f"\\\\]/',
            static fn (array $match): string => match ($match[0]) {
                '"' => '\\"',
                '\\' => '\\\\',
                "\x08" => '\\b',
                "\x0c" => '\\f',
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                default => sprintf('\\u%04x', ord($match[0])),
            },
            $value,
        );

        return '"'.$escaped.'"';
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private static function list(array $values): string
    {
        return '['.implode(',', array_map(self::canonicalize(...), $values)).']';
    }

    /**
     * @param  array<int|string, mixed>  $members
     */
    private static function object(array $members): string
    {
        $sortKeys = [];
        foreach (array_keys($members) as $key) {
            $sortKeys[(string) $key] = mb_convert_encoding((string) $key, 'UTF-16BE', 'UTF-8');
        }
        uasort($sortKeys, strcmp(...));
        $parts = [];
        foreach (array_keys($sortKeys) as $key) {
            $parts[] = self::string((string) $key).':'.self::canonicalize($members[$key]);
        }

        return '{'.implode(',', $parts).'}';
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function members(stdClass $object): array
    {
        return get_object_vars($object);
    }
}
