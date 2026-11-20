<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Immutable original refund route bound to the charge's original consent binding and merchant revision.
 */
#[Api(since: '1.0')]
final readonly class OriginalRefundRouteView
{
    public function __construct(
        public int $id,
        public string $sellerRef,
        public string $environment,
        public string $operationRef,
        public string $billingAccountRef,
        public int $originalBindingId,
        public string $originalConsentRef,
        public int $originalConsentVersion,
        public string $originalMerchantAccountRef,
        public string $originalMerchantConfigRevision,
        public string $originalProviderId,
        public string $originalDriverId,
        public string $originalDriverContractVersion,
        public string $originalPaymentMethodRef,
        public string $routeDigest,
        public DateTimeImmutable $recordedAt,
    ) {}
}
