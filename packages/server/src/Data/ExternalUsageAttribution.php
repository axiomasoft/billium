<?php

declare(strict_types=1);

namespace Billium\Server\Data;

use Packman\Attributes\Api;

/** Seller-specific host terms with independent source, subject, commercial and authenticated identities. */
#[Api(since: '1.0')]
final readonly class ExternalUsageAttribution
{
    public function __construct(
        public SellerScope $scope,
        public string $mappingRevisionRef,
        public string $sourceRef,
        public string $subjectRef,
        public string $payerRef,
        public string $customerRef,
        public string $billingAccountRef,
        public string $actorRef,
    ) {}
}
