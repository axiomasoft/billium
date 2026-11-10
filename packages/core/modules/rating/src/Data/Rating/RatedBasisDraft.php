<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Rating;

use Billium\Contracts\Attributes\Api;
use Billium\Rating\Enums\ChargeKind;

#[Api]
final readonly class RatedBasisDraft
{
    /** @param list<RatedLineDraft> $lines */
    public function __construct(
        public string $basisRef,
        public string $inputsDigest,
        public string $ratedDigest,
        public ChargeKind $chargeKind,
        public ?string $noChargeReason,
        public array $lines,
        public string $netMinor,
        public string $taxMinor,
        public string $grossMinor,
    ) {}
}
