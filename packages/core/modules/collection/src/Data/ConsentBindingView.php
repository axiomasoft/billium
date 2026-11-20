<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\ConsentPurpose;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Immutable consent proof/reference record; current revocation state lives separately in ConsentGuardStateView.
 */
#[Api(since: '1.0')]
final readonly class ConsentBindingView
{
    /**
     * @param  list<ConsentPurpose>  $allowedPurposes
     * @param  list<string>  $allowedCurrencies
     */
    public function __construct(
        public int $id,
        public string $sellerRef,
        public string $environment,
        public string $billingAccountRef,
        public string $customerRef,
        public string $consentRef,
        public int $consentVersion,
        public string $paymentMethodRef,
        public string $tokenLocator,
        public string $merchantAccountRef,
        public string $merchantConfigRevision,
        public string $providerId,
        public string $driverId,
        public string $driverContractVersion,
        public string $capabilitySnapshotRef,
        public string $capabilityDigest,
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
        public string $proofDigest,
        public ?int $supersedesBindingId,
        public DateTimeImmutable $createdAt,
    ) {}
}
