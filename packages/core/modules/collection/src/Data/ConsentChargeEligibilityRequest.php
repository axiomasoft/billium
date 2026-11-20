<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\ConsentPurpose;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ConsentChargeEligibilityRequest
{
    public function __construct(
        public CollectionScope $scope,
        public string $billingAccountRef,
        public string $consentRef,
        public int $consentVersion,
        public string $merchantAccountRef,
        public string $merchantConfigRevision,
        public ConsentPurpose $purpose,
        public string $currency,
        public int $amountMinor,
        public DateTimeImmutable $asOf,
        public int $periodCommittedMinor = 0,
        public ?int $expectedCancellationGeneration = null,
        public ?string $authorizationRef = null,
        public ?string $businessPaymentIntentRef = null,
    ) {}
}
