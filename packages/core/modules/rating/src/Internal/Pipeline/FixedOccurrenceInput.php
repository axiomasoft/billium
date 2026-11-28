<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Pipeline;

use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Rating\FrozenAggregate;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Proration\QuantitySnapshotScope;
use Billium\Rating\Internal\Storage\RatingRows;
use Packman\Attributes\Internal;

/** Synthetic occurrence and host-approved quantity inputs do not depend on metered usage. */
#[Internal]
final class FixedOccurrenceInput
{
    public static function matches(FrozenAggregate $aggregate): bool
    {
        return in_array($aggregate->snapshot['kind'] ?? null, ['fixed_occurrence/1', 'approved_quantity/1'], true);
    }

    /** @param array<string, mixed> $group */
    public static function freeze(string $seller, string $environment, string $occurrence, string $sourceDigest,
        string $occurrenceDigest, int $start, int $end, array $group, PriceVersion $price): FrozenAggregate
    {
        if ($price->strategyId !== StrategyId::Fixed) {
            RatingComputation::refuse('fixed_input_requires_fixed_price');
        }
        $snapshot = ['kind' => 'fixed_occurrence/1', 'occurrenceRef' => $occurrence, 'sourceDigest' => $sourceDigest,
            'occurrenceInputsDigest' => $occurrenceDigest, 'termsDigest' => $price->termsDigest,
            'window' => ['sellerRef' => $seller, 'environment' => $environment, 'startMicros' => $start, 'endMicros' => $end, 'group' => $group],
            'quantity' => '1', 'quantityScale' => 0];
        $digest = CanonicalJson::digest($snapshot);

        return new FrozenAggregate(componentRef: RatingRows::text($group['componentRef'] ?? null), metricRef: 'billium.fixed_occurrence', metricVersion: '1',
            snapshotRef: 'fixed_'.substr($digest, 7, 40), snapshotDigest: $digest, windowStartMicros: $start, windowEndMicros: $end,
            completenessFrontierDigest: $sourceDigest, quantity: '1', quantityScale: 0, snapshot: $snapshot);
    }

    public static function assertValid(FrozenRatingInputs $inputs, FrozenAggregate $aggregate, PriceVersion $price): void
    {
        if (($aggregate->snapshot['kind'] ?? null) === 'approved_quantity/1') {
            QuantitySnapshotScope::assertValid(inputs: $inputs, aggregate: $aggregate, price: $price);

            return;
        }
        if (! self::matches($aggregate)) {
            return;
        }
        $group = RatingRows::map(json_decode($inputs->attribution, true, 32, JSON_THROW_ON_ERROR));
        $group['billingAccountRef'] = $inputs->billingAccountRef;
        $group['agreementRevision'] = $inputs->agreementRevision;
        $group['componentRef'] = $aggregate->componentRef;
        $expected = self::freeze(seller: $inputs->sellerRef, environment: $inputs->environment, occurrence: $inputs->occurrenceRef,
            sourceDigest: $inputs->sourceDigest, occurrenceDigest: $inputs->occurrenceInputsDigest,
            start: $inputs->periodStartMicros, end: $inputs->periodEndMicros, group: $group, price: $price);
        if (CanonicalJson::encode($expected) !== CanonicalJson::encode($aggregate)) {
            RatingComputation::refuse('fixed_input_invalid');
        }
    }
}
