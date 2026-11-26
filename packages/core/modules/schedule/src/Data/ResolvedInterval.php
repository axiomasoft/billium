<?php

declare(strict_types=1);

namespace Billium\Schedule\Data;

use Billium\Schedule\Internal\Canonical;
use Billium\Schedule\Internal\Instants;
use Billium\Schedule\Internal\ScheduleProblems;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Immutable UTC boundaries plus retained source resolution evidence (D56; owner schedule). */
#[Api]
final readonly class ResolvedInterval
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public string $start,
        public string $end,
        public string $localStart,
        public string $localEnd,
        public string $cutoffAt,
        public string $tzdbVersion,
        public array $evidence,
    ) {
        if (Instants::utc($start) >= Instants::utc($end)) {
            ScheduleProblems::refuse('non_positive_period');
        }
        Instants::utc($cutoffAt);
    }

    public function contains(DateTimeImmutable $instant): bool
    {
        return $instant >= Instants::utc($this->start) && $instant < Instants::utc($this->end);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['start' => $this->start, 'end' => $this->end, 'localStart' => $this->localStart, 'localEnd' => $this->localEnd,
            'cutoffAt' => $this->cutoffAt, 'tzdbVersion' => $this->tzdbVersion, 'evidence' => $this->evidence];
    }

    public function digest(): string
    {
        return Canonical::digest($this->toArray());
    }
}
