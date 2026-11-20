<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\ConsentPurpose;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class RegisterConsentBinding
{
    /**
     * @param  list<ConsentPurpose>  $allowedPurposes
     * @param  list<string>  $allowedCurrencies
     */
    public function __construct(
        public string $commandKey,
        public CollectionScope $scope,
        public string $billingAccountRef,
        public string $customerRef,
        public string $consentRef,
        public int $consentVersion,
        public string $paymentMethodRef,
        public string $tokenLocator,
        public string $merchantAccountRef,
        public string $merchantConfigRevision,
        public array $allowedPurposes,
        public array $allowedCurrencies,
        public ?int $perChargeCapMinor,
        public ?int $perPeriodCapMinor,
        public ?string $capCurrency,
        public DateTimeImmutable $capturedAt,
        public DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validUntil,
        public string $sourceEvidenceRef,
        public string $sourceEvidenceDigest,
        public int $sourceRevision = 0,
        public int $cancellationGeneration = 0,
        public ?int $supersedesConsentVersion = null,
    ) {}
}
