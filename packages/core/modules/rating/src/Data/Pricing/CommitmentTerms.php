<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Pricing;

use Billium\Contracts\Attributes\Api;

#[Api]
final readonly class CommitmentTerms
{
    public function __construct(
        public string $sellerRef,
        public string $billingAccountRef,
        public string $componentRef,
        public string $currency,
        public string $periodRef,
        public ?int $minimumMinor,
        public ?int $maximumMinor,
        public bool $consumedByAlreadyBilled,
        public string $appliesAt,
    ) {}
}
