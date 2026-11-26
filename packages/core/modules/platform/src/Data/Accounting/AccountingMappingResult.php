<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Accounting;

use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Discriminated result returned by AccountingMapper::map().
 */
#[Api(since: '1.0')]
final readonly class AccountingMappingResult
{
    public function __construct(
        public AccountingMappingStatus $status,
        public ?AccountingFactEnvelope $fact = null,
        public ?QuarantinedFactRecord $quarantined = null,
        public ?string $reason = null,
    ) {
        if ($status === AccountingMappingStatus::Mapped && ($fact === null || $quarantined !== null)) {
            throw new InvalidArgumentException('Mapped result requires an AccountingFactEnvelope and no quarantine record');
        }
        if ($status === AccountingMappingStatus::QuarantinedUnsupportedSchema && ($quarantined === null || $fact !== null)) {
            throw new InvalidArgumentException('Quarantined result requires a QuarantinedFactRecord and no fact envelope');
        }
        if ($status === AccountingMappingStatus::IgnoredNonMonetary && ($fact !== null || $quarantined !== null)) {
            throw new InvalidArgumentException('Ignored result must not carry a fact envelope or quarantine record');
        }
    }

    public static function mapped(AccountingFactEnvelope $fact): self
    {
        return new self(AccountingMappingStatus::Mapped, fact: $fact);
    }

    public static function ignored(string $reason): self
    {
        return new self(AccountingMappingStatus::IgnoredNonMonetary, reason: $reason);
    }

    public static function quarantined(QuarantinedFactRecord $record): self
    {
        return new self(AccountingMappingStatus::QuarantinedUnsupportedSchema, quarantined: $record, reason: $record->reason);
    }
}
