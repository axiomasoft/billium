<?php

declare(strict_types=1);

namespace Billium\Documents\Internal\Support;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runtime\Caller;
use Billium\Documents\Data\DocumentScope;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

/** Strict PostgreSQL row decoding and caller/input guards for documents services. */
#[Internal]
final class RowCast
{
    public static function assertScope(Caller $caller, DocumentScope|BillingAccountScope $scope): void
    {
        if ($caller->sellerRef !== $scope->sellerRef || $caller->environment !== $scope->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'billium.documents.scope_mismatch');
        }
    }

    public static function bounded(string $value, string $field, int $max = 200): string
    {
        if ($value === '' || strlen($value) > $max || str_contains($value, "\0")) {
            throw new ProblemException(ProblemCode::ValidationInvalid, $field.' must be a nonempty bounded string');
        }

        return $value;
    }

    public static function str(mixed $value, string $field): string
    {
        return is_string($value) ? $value : throw new LogicException("Expected PostgreSQL text for {$field}");
    }

    public static function optStr(mixed $value, string $field): ?string
    {
        return $value === null ? null : self::str($value, $field);
    }

    public static function integer(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }

        throw new LogicException("Expected PostgreSQL integer for {$field}");
    }

    public static function optInt(mixed $value, string $field): ?int
    {
        return $value === null ? null : self::integer($value, $field);
    }

    public static function boolean(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [1, 't', 'true'], true)) {
            return true;
        }
        if (in_array($value, [0, 'f', 'false'], true)) {
            return false;
        }

        throw new LogicException("Expected PostgreSQL boolean for {$field}");
    }

    public static function timestamp(mixed $value, string $field): string
    {
        return new DateTimeImmutable(self::str($value, $field))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    public static function optTimestamp(mixed $value, string $field): ?string
    {
        return $value === null ? null : self::timestamp($value, $field);
    }

    /** @return array<string, mixed> */
    public static function jsonObject(mixed $value, string $field): array
    {
        $decoded = json_decode(self::str($value, $field), false, 512, JSON_THROW_ON_ERROR);
        if (! $decoded instanceof stdClass) {
            throw new LogicException("Expected JSON object for {$field}");
        }

        return self::objectToArray($decoded);
    }

    /** @return list<mixed> */
    public static function jsonList(mixed $value, string $field): array
    {
        $decoded = json_decode(self::str($value, $field), false, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new LogicException("Expected JSON array for {$field}");
        }

        $result = [];
        foreach ($decoded as $item) {
            $result[] = self::decodeValue($item);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private static function objectToArray(stdClass $object): array
    {
        $out = [];
        foreach (get_object_vars($object) as $k => $v) {
            $out[(string) $k] = self::decodeValue($v);
        }

        return $out;
    }

    private static function decodeValue(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            return self::objectToArray($value);
        }
        if (is_array($value)) {
            return array_map(self::decodeValue(...), $value);
        }

        return $value;
    }
}
