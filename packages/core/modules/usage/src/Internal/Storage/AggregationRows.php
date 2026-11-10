<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Storage;

use Billium\Usage\Internal\Errors\UsageProblems;

final class AggregationRows
{
    public static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw UsageProblems::error('stored_evidence_invalid');
    }

    public static function integer(mixed $value): int
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1)
            ? (int) $value : throw UsageProblems::error('stored_evidence_invalid');
    }

    /** @return array<string, mixed> */
    public static function map(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true, 64, JSON_THROW_ON_ERROR) : $value;
        if (! is_array($decoded)) {
            throw UsageProblems::error('stored_evidence_invalid');
        }
        foreach ($decoded as $key => $_) {
            if (! is_string($key)) {
                throw UsageProblems::error('stored_evidence_invalid');
            }
        }

        return $decoded;
    }

    /** @return list<mixed> */
    public static function items(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true, 64, JSON_THROW_ON_ERROR) : $value;

        return is_array($decoded) && array_is_list($decoded) ? $decoded : throw UsageProblems::error('stored_evidence_invalid');
    }

    /** @return array<string, string> */
    public static function group(mixed $value): array
    {
        return array_map(self::text(...), self::map($value));
    }
}
