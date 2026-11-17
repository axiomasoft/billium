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
use Packman\Attributes\Internal;
use Throwable;

#[Internal]
final class QuantitySnapshotScope
{
    /** @param array<string, mixed> $group
     * @param  array<string, mixed>  $quantitySnapshot
     */
    public static function freeze(string $seller, string $environment, string $occurrence, string $occurrenceDigest,
        int $start, int $end, array $group, PriceVersion $price, array $quantitySnapshot): FrozenAggregate
    {
        $quantity = RatingRows::text($quantitySnapshot['quantity'] ?? null);
        $scale = strlen(explode('.', $quantity, 2)[1] ?? '');
        $sourceDigest = RatingRows::text($quantitySnapshot['sourceDigest'] ?? null);
        $snapshot = ['kind' => 'approved_quantity/1', 'occurrenceRef' => $occurrence,
            'occurrenceInputsDigest' => $occurrenceDigest, 'termsDigest' => $price->termsDigest, 'sourceDigest' => $sourceDigest,
            'window' => ['sellerRef' => $seller, 'environment' => $environment, 'startMicros' => $start, 'endMicros' => $end, 'group' => $group],
            'quantity' => $quantity, 'quantityScale' => $scale, 'quantitySnapshotDigest' => $quantitySnapshot['digest'] ?? null];

        return new FrozenAggregate(componentRef: RatingRows::text($group['componentRef'] ?? null), metricRef: 'billium.approved_quantity', metricVersion: '1',
            snapshotRef: RatingRows::text($quantitySnapshot['snapshotRef'] ?? null), snapshotDigest: CanonicalJson::digest($snapshot),
            windowStartMicros: $start, windowEndMicros: $end, completenessFrontierDigest: $sourceDigest,
            quantity: $quantity, quantityScale: $scale, snapshot: $snapshot, quantitySnapshot: $quantitySnapshot);
    }

    public static function assertValid(FrozenRatingInputs $inputs, FrozenAggregate $aggregate, PriceVersion $price): void
    {
        $approved = ($aggregate->snapshot['kind'] ?? null) === 'approved_quantity/1';
        if ($aggregate->quantitySnapshot === null) {
            if ($approved) {
                RatingComputation::refuse('quantity_snapshot_invalid');
            }

            return;
        }
        try {
            $snapshot = (new Codec)->decode(CanonicalJson::encode($aggregate->quantitySnapshot));
            if (! $snapshot instanceof QuantitySnapshot) {
                RatingComputation::refuse('quantity_snapshot_invalid');
            }
            $attribution = json_decode($inputs->attribution, true, 32, JSON_THROW_ON_ERROR);
            if ($approved) {
                $group = RatingRows::map($attribution);
                $group['billingAccountRef'] = $inputs->billingAccountRef;
                $group['agreementRevision'] = $inputs->agreementRevision;
                $group['componentRef'] = $aggregate->componentRef;
                $expected = self::freeze(seller: $inputs->sellerRef, environment: $inputs->environment, occurrence: $inputs->occurrenceRef,
                    occurrenceDigest: $inputs->occurrenceInputsDigest, start: $inputs->periodStartMicros, end: $inputs->periodEndMicros,
                    group: $group, price: $price, quantitySnapshot: $aggregate->quantitySnapshot);
                if (CanonicalJson::encode($expected) !== CanonicalJson::encode($aggregate)) {
                    RatingComputation::refuse('quantity_snapshot_invalid');
                }
            }
            $identity = get_object_vars($snapshot->attribution);
            $start = ScheduleOccurrenceAdapter::micros(new DateTimeImmutable(RatingRows::text($snapshot->effectiveInterval->start))->format('Y-m-d\TH:i:s.uP'));
            $end = ScheduleOccurrenceAdapter::micros(new DateTimeImmutable(RatingRows::text($snapshot->effectiveInterval->end))->format('Y-m-d\TH:i:s.uP'));
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
