<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Services;

use Billium\Rating\Contracts\RatingQueries;
use Billium\Rating\Data\Rating\BookingReceipt;
use Billium\Rating\Data\Rating\RatedBasisDraft;
use Billium\Rating\Enums\BookingOutcome;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Billium\Rating\Internal\Storage\RatingRows;
use Billium\Rating\Internal\Storage\RatingStore;

final readonly class DatabaseRatingQueries implements RatingQueries
{
    public function __construct(private RatingStore $store, private RatingComputation $computation) {}

    public function basis(string $basisRef): ?RatedBasisDraft
    {
        $row = $this->store->basis($basisRef);

        return $row === null ? null : $this->store->draft($row);
    }

    public function bookingByBasis(string $basisRef): ?BookingReceipt
    {
        $row = $this->store->basis($basisRef);
        if ($row === null) {
            return null;
        }
        $booking = $this->store->booking(RatingRows::integer($row->id));

        return new BookingReceipt(basisRef: $basisRef, outcome: BookingOutcome::from(RatingRows::text($booking->outcome ?? 'unposted')),
            obligationRef: RatingRows::nullableText($booking?->obligation_ref), grossMinor: RatingRows::decimal($row->gross_minor), currency: RatingRows::text($row->currency));
    }

    public function frontier(string $occurrenceRef): array
    {
        return $this->store->frontier($occurrenceRef);
    }

    public function replay(string $basisRef): RatedBasisDraft
    {
        $row = $this->store->basis($basisRef) ?? RatingComputation::refuse('basis_unknown');
        $inputs = $this->store->inputs($row);
        $draft = $this->computation->compute(inputs: $inputs, proration: $inputs->proration,
            predecessorBasisRef: RatingRows::nullableText($row->predecessor_basis_ref),
            correctionRevision: $row->correction_revision === null ? 0 : RatingRows::integer($row->correction_revision));
        if (CanonicalJson::encode($draft) !== CanonicalJson::encode($this->store->draft($row))) {
            RatingComputation::refuse('replay_mismatch');
        }

        return $draft;
    }
}
