<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Rating;

use Billium\Contracts\Attributes\Api;
use Billium\Rating\Internal\Pipeline\InputsDigest;

#[Api]
final readonly class FrozenRatingInputs
{
    /**
     * @param  list<FrozenAggregate>  $aggregates
     * @param  list<FrozenPrice>  $prices
     * @param  list<FrozenTax>  $taxes
     */
    public function __construct(
        public string $sellerRef,
        public string $environment,
        public string $billingAccountRef,
        public string $occurrenceRef,
        public int $periodStartMicros,
        public int $periodEndMicros,
        public string $attribution,
        public string $agreementRevision,
        public string $currency,
        public int $currencyExponent,
        public array $aggregates,
        public array $prices,
        public array $taxes,
        public string $occurrenceInputsDigest,
        public string $sourceDigest,
        public string $frontierExpectedDigest,
        public int $frontierExpectedRowVersion,
        public ?string $noChargeReason = null,
    ) {}

    public function digest(): string
    {
        return InputsDigest::compute($this);
    }
}
