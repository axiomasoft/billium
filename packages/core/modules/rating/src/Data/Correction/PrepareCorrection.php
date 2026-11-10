<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Correction;

use Billium\Contracts\Attributes\Api;
use Billium\Rating\Enums\CorrectionKind;
use Billium\Rating\Enums\ReleasePolicy;
use DateTimeImmutable;

#[Api]
final readonly class PrepareCorrection
{
    public function __construct(
        public string $sellerRef,
        public string $environment,
        public string $parentOccurrenceRef,
        public CorrectionKind $kind,
        public ?HostAmendment $amendment,
        public string $reasonCode,
        public ?ReleasePolicy $releasePolicy,
        public ?DateTimeImmutable $correctionEffectiveDate = null,
    ) {}
}
