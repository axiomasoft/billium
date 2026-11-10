<?php

declare(strict_types=1);

namespace Billium\Schedule\Data\Commands;

use Billium\Contracts\Attributes\Api;
use DateTimeImmutable;

#[Api]
final readonly class RegisterCorrectionChild
{
    public function __construct(
        public string $sellerRef, public string $environment, public string $parentOccurrenceRef,
        public DateTimeImmutable $originalPeriodStart, public DateTimeImmutable $originalPeriodEnd,
        public int $correctionRevision, public ?int $predecessorRevision,
        public string $predecessorRatedBasisRef, public string $sourceFrontierDigest,
        public int $expectedHeadRevision, public ?string $expectedHeadFrontierDigest,
        public ?string $taxCorrectionPolicyRef, public ?string $taxCorrectionPolicyVersion,
        public string $reasonCode, public string $obligationBasisRef,
    ) {}
}
