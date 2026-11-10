<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Proration;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\QuantitySnapshot;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Rating\FrozenAggregate;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Internal\Adapters\ScheduleOccurrenceAdapter;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Billium\Rating\Internal\Storage\RatingRows;
use DateTimeImmutable;
use Throwable;

final class QuantitySnapshotScope
{
    public static function assertValid(FrozenRatingInputs $inputs, FrozenAggregate $aggregate, PriceVersion $price): void
    {
        if ($aggregate->quantitySnapshot === null) {
            return;
        }
        try {
            $snapshot = (new Codec)->decode(CanonicalJson::encode($aggregate->quantitySnapshot));
            if (! $snapshot instanceof QuantitySnapshot) {
                RatingComputation::refuse('quantity_snapshot_invalid');
            }
            $attribution = json_decode($inputs->attribution, true, 32, JSON_THROW_ON_ERROR);
            $identity = get_object_vars($snapshot->attribution);
            $start = ScheduleOccurrenceAdapter::micros((new DateTimeImmutable(RatingRows::text($snapshot->effectiveInterval->start)))->format('Y-m-d\TH:i:s.uP'));
            $end = ScheduleOccurrenceAdapter::micros((new DateTimeImmutable(RatingRows::text($snapshot->effectiveInterval->end)))->format('Y-m-d\TH:i:s.uP'));
            if ($snapshot->sellerRef !== $inputs->sellerRef || $snapshot->unit !== $price->unit
                || $snapshot->effectiveRevision < 1 || $snapshot->prorationPolicy !== 'whole_interval/1'
                || $start !== $inputs->periodStartMicros || $end !== $inputs->periodEndMicros
                || bccomp(RatingRows::decimal($snapshot->quantity), RatingRows::decimal($aggregate->quantity), 9) !== 0
                || ($identity['billingAccountRef'] ?? null) !== $inputs->billingAccountRef
                || ($identity['componentRef'] ?? null) !== $aggregate->componentRef
                || (! is_int($identity['agreementRevision'] ?? null) && ! is_string($identity['agreementRevision'] ?? null))
                || (string) $identity['agreementRevision'] !== $inputs->agreementRevision
                || ! is_array($attribution)
                || ($identity['customerRef'] ?? null) !== ($attribution['customerRef'] ?? null)
                || ($identity['agreementRef'] ?? null) !== ($attribution['agreementRef'] ?? null)) {
                RatingComputation::refuse('quantity_snapshot_invalid');
            }
        } catch (Throwable) {
            RatingComputation::refuse('quantity_snapshot_invalid');
        }
    }
}
