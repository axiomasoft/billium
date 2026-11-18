<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Adapters;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Billium\Rating\Internal\Time\Micros;
use Billium\Schedule\Contracts\CorrectionParticipants;
use Billium\Schedule\Contracts\OccurrenceLocks;
use Billium\Schedule\Contracts\OccurrenceParticipants;
use Billium\Schedule\Contracts\ScheduleQueries;
use Billium\Schedule\Data\Commands\AdvanceOccurrence;
use Billium\Schedule\Data\Commands\RegisterCorrectionChild;
use Billium\Schedule\Data\CorrectionReceipt;
use Billium\Schedule\Data\OccurrenceView;
use Billium\Schedule\Enums\OccurrenceState;
use Packman\Attributes\Internal;

#[Internal]
final readonly class ScheduleOccurrenceAdapter
{
    public function __construct(private ScheduleQueries $queries, private OccurrenceLocks $locks, private OccurrenceParticipants $participants,
        private CorrectionParticipants $corrections) {}

    public function find(string $ref): ?OccurrenceView
    {
        return $this->queries->occurrence($ref);
    }

    public function occurrence(string $ref): OccurrenceView
    {
        return $this->find($ref) ?? RatingComputation::refuse('occurrence_not_bookable');
    }

    /** @return array{revision: int, frontierDigest: ?string} */
    public function correctionHead(string $sellerRef, string $parentOccurrenceRef): array
    {
        return $this->queries->correctionHead(sellerRef: $sellerRef, parentOccurrenceRef: $parentOccurrenceRef);
    }

    public function registerCorrectionChild(TransactionContext $context, RegisterCorrectionChild $command): CorrectionReceipt
    {
        return $this->corrections->registerCorrectionChild(context: $context, command: $command);
    }

    public function lock(TransactionContext $context, string $ref): void
    {
        $this->locks->acquire(context: $context, occurrenceRefs: [$ref]);
    }

    public function assertBookable(FrozenRatingInputs $inputs): void
    {
        $occurrence = $this->occurrence($inputs->occurrenceRef);
        if (! in_array($occurrence->state, [OccurrenceState::Planned, OccurrenceState::Rated], true)
            || ($occurrence->applicableRevision !== $occurrence->createdRevision && ! $occurrence->applicableRevisionConfirmed)
            || $occurrence->applicableAction === 'pause'
            || $occurrence->applicableInputsDigest !== $inputs->occurrenceInputsDigest
            || $occurrence->sellerRef !== $inputs->sellerRef || $occurrence->environment !== $inputs->environment
            || $occurrence->inputsDigest !== $inputs->occurrenceInputsDigest
            || Micros::of($occurrence->periodStart) !== $inputs->periodStartMicros
            || Micros::of($occurrence->periodEnd) !== $inputs->periodEndMicros
            || ($occurrence->state === OccurrenceState::Rated && $occurrence->ratedBasisRef !== RatingComputation::basisRef(inputs: $inputs))) {
            RatingComputation::refuse('occurrence_not_bookable');
        }
    }

    public function advance(TransactionContext $context, FrozenRatingInputs $inputs, string $basis, bool $booked): void
    {
        $this->participants->advanceOccurrence(context: $context, command: new AdvanceOccurrence(
            sellerRef: $inputs->sellerRef, environment: $inputs->environment, occurrenceRef: $inputs->occurrenceRef,
            to: $booked ? OccurrenceState::Obligated : OccurrenceState::Rated, ratedBasisRef: $basis));
    }
}
