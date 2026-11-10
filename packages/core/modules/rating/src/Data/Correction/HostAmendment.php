<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Correction;

use Billium\Contracts\Attributes\Api;
use Billium\Rating\Enums\ProrationPolicyId;
use DateTimeImmutable;

#[Api]
final readonly class HostAmendment
{
    public function __construct(
        public string $amendmentRef,
        public int $revision,
        public DateTimeImmutable $effectiveAt,
        public ProrationPolicyId $prorationPolicy,
        public string $kind,
        public ?string $timezone,
    ) {}
}
