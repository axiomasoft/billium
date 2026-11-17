<?php

declare(strict_types=1);

namespace Billium\Contracts\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Api;
use stdClass;

/**
 * A validated envelope of the spec revision. `raw` is the received JSON object (unknown optional members
 * of open envelopes included); treat it as read-only. `schema` is the envelope's schema name, for example
 * "payment.execute.v1". The typed members come from the generated subclasses.
 */
#[Api(since: '1.0')]
abstract readonly class Envelope
{
    public function __construct(public stdClass $raw, public string $schema) {}

    /**
     * Builds the typed envelope from an object that already passed the schema of the revision.
     */
    abstract public static function fromRaw(stdClass $raw): self;

    final protected static function string(stdClass $raw, string $key): string
    {
        return self::optString($raw, $key) ?? throw self::missing($key);
    }

    final protected static function optString(stdClass $raw, string $key): ?string
    {
        $value = self::member($raw, $key);
        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : throw self::wrongType($key, 'string');
    }

    final protected static function int(stdClass $raw, string $key): int
    {
        return self::optInt($raw, $key) ?? throw self::missing($key);
    }

    final protected static function optInt(stdClass $raw, string $key): ?int
    {
        $value = self::member($raw, $key);
        if ($value === null) {
            return null;
        }

        return is_int($value) ? $value : throw self::wrongType($key, 'integer');
    }

    final protected static function money(stdClass $raw, string $key): Money
    {
        return self::optMoney($raw, $key) ?? throw self::missing($key);
    }

    final protected static function optMoney(stdClass $raw, string $key): ?Money
    {
        $value = self::member($raw, $key);

        return $value === null ? null : Money::fromWire($value);
    }

    final protected static function object(stdClass $raw, string $key): stdClass
    {
        return self::optObject($raw, $key) ?? throw self::missing($key);
    }

    final protected static function optObject(stdClass $raw, string $key): ?stdClass
    {
        $value = self::member($raw, $key);
        if ($value === null) {
            return null;
        }

        return $value instanceof stdClass ? $value : throw self::wrongType($key, 'object');
    }

    /** @return list<mixed> */
    final protected static function list(stdClass $raw, string $key): array
    {
        return self::optList($raw, $key) ?? throw self::missing($key);
    }

    /** @return list<mixed>|null */
    final protected static function optList(stdClass $raw, string $key): ?array
    {
        $value = self::member($raw, $key);

        return $value === null ? null : (is_array($value) && array_is_list($value) ? $value : throw self::wrongType($key, 'array'));
    }

    final protected static function bool(stdClass $raw, string $key): bool
    {
        return self::optBool($raw, $key) ?? throw self::missing($key);
    }

    final protected static function optBool(stdClass $raw, string $key): ?bool
    {
        $value = self::member($raw, $key);

        return $value === null ? null : (is_bool($value) ? $value : throw self::wrongType($key, 'boolean'));
    }

    private static function member(stdClass $raw, string $key): mixed
    {
        return property_exists($raw, $key) ? $raw->{$key} : null;
    }

    private static function missing(string $key): ProblemException
    {
        return new ProblemException(ProblemCode::ValidationInvalid, "member '{$key}' is required");
    }

    private static function wrongType(string $key, string $type): ProblemException
    {
        return new ProblemException(ProblemCode::ValidationInvalid, "member '{$key}' must be {$type}");
    }
}
