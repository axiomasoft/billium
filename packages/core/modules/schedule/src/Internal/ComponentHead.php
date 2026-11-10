<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal;

use Billium\Schedule\Data\ScheduleRevisionTerms;
use DateTimeImmutable;
use stdClass;

final readonly class ComponentHead
{
    public function __construct(
        public string $agreementRef,
        public int $revision,
        public int $cancellationGeneration,
        public int $fundingRevision,
        public string $action,
        public ?DateTimeImmutable $coveredUntil,
        public ?ScheduleRevisionTerms $terms,
    ) {}

    public static function fromRow(stdClass $row): self
    {
        return new self(agreementRef: Payload::text($row->agreement_ref), revision: Payload::integer($row->agreement_revision),
            cancellationGeneration: Payload::integer($row->cancellation_generation), fundingRevision: Payload::integer($row->funding_revision),
            action: Payload::text($row->action), coveredUntil: $row->covered_until === null ? null : new DateTimeImmutable(Payload::text($row->covered_until)),
            terms: $row->terms === null ? null : ScheduleRevisionTerms::fromArray(Payload::decode($row->terms)));
    }
}
