<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Storage;

use Billium\Usage\Data\SourceCompleteness;
use Billium\Usage\Enums\CompletenessStatus;
use Billium\Usage\Internal\Errors\UsageProblems;
use Packman\Attributes\Internal;

#[Internal]
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

    /**
     * A member that a stored document must carry; absence is corruption, not an implicit null.
     *
     * @param  array<string, mixed>  $map
     */
    public static function member(array $map, string $key): mixed
    {
        return array_key_exists($key, $map) ? $map[$key] : throw UsageProblems::error('stored_evidence_invalid');
    }

    /** Rebuilds one frozen completeness frontier entry, re-verifying its evidence digest. */
    public static function completeness(mixed $value): SourceCompleteness
    {
        $item = self::map($value);
        $repair = self::member($item, 'repairOrReplayRef');

        return new SourceCompleteness(
            sourceRef: self::text(self::member($item, 'sourceRef')), evidenceSeq: self::integer(self::member($item, 'evidenceSeq')),
            status: CompletenessStatus::tryFrom(self::text(self::member($item, 'status'))) ?? throw UsageProblems::error('stored_evidence_invalid'),
            rangeStartMicros: self::integer(self::member($item, 'rangeStartMicros')), rangeEndMicros: self::integer(self::member($item, 'rangeEndMicros')),
            watermarkMicros: self::integer(self::member($item, 'watermarkMicros')),
            repairOrReplayRef: $repair === null ? null : self::text($repair),
            evidenceDigest: self::text(self::member($item, 'evidenceDigest')),
        );
    }
}
