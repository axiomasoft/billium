<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Tax;

use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api]
final readonly class TaxResolutionRequest
{
    public function __construct(
        public string $sellerRef,
        public string $environment,
        public string $billingAccountRef,
        public string $jurisdictionHint,
        public string $componentRef,
        public string $currency,
        public DateTimeImmutable $taxPoint,
        public string $inputDigest,
    ) {}
}
