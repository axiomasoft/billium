<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Rating;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Data\Envelopes\QuantitySnapshot;

#[Api]
final readonly class PrepareRating
{
    /**
     * Versions are pinned host agreement inputs, never current-price selectors (D56).
     *
     * @param  list<array{componentRef: string, snapshotRef: string, priceRef: string, priceVersion: int, quantitySnapshot?: QuantitySnapshot}>  $components
     */
    public function __construct(
        public string $occurrenceRef,
        public string $billingAccountRef,
        public string $attribution,
        public string $agreementRevision,
        public string $occurrenceInputsDigest,
        public string $sourceDigest,
        public array $components,
        public ?string $noChargeReason = null,
        public string $jurisdictionHint = 'host',
    ) {}
}
