<?php

declare(strict_types=1);

namespace Billium\Schedule\Data;

use Billium\Schedule\Enums\OccurrenceState;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Read model of one occurrence incarnation with the schedule revision that currently applies to its period.
 *
 * The applicable revision fields are null when the schedule has no revision covering the period.
 */
#[Api]
final readonly class OccurrenceView
{
    public function __construct(
        public string $occurrenceRef,
        public string $sellerRef,
        public string $environment,
        public string $componentRef,
        public OccurrenceState $state,
        public DateTimeImmutable $periodStart,
        public DateTimeImmutable $periodEnd,
        public string $inputsDigest,
        public int $createdRevision,
        public ?string $ratedBasisRef,
        public ?int $applicableRevision,
        public bool $applicableRevisionConfirmed,
        public ?string $applicableInputsDigest,
        public ?string $applicableAction,
    ) {}
}
