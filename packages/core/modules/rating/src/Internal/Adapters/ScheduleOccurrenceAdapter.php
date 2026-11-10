<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Adapters;

use Billium\Core\Contracts\TransactionContext;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Billium\Rating\Internal\Storage\RatingRows;
use Billium\Schedule\Contracts\OccurrenceLocks;
use Billium\Schedule\Contracts\OccurrenceParticipants;
use Billium\Schedule\Contracts\ScheduleQueries;
use Billium\Schedule\Data\Commands\AdvanceOccurrence;
use Billium\Schedule\Enums\OccurrenceState;
use DateTimeImmutable;

final readonly class ScheduleOccurrenceAdapter
{
    public function __construct(private ScheduleQueries $queries, private OccurrenceLocks $locks, private OccurrenceParticipants $participants) {}

    /** @return array<string, mixed> */
    public function occurrence(string $ref): array
    {
        return $this->queries->occurrence($ref) ?? RatingComputation::refuse('occurrence_not_bookable');
    }

    public function lock(TransactionContext $context, string $ref): void
    {
        $this->locks->acquire(context: $context, occurrenceRefs: [$ref]);
    }

    public function assertBookable(FrozenRatingInputs $inputs): void
    {
        $row = $this->occurrence($inputs->occurrenceRef);
        if (! in_array($row['state'], ['planned', 'rated'], true)
            || ($row['applicable_revision'] !== $row['created_revision'] && ! $row['applicable_revision_confirmed'])
            || $row['applicable_action'] === 'pause'
            || $row['applicable_inputs_digest'] !== $inputs->occurrenceInputsDigest
            || $row['seller_ref'] !== $inputs->sellerRef || $row['environment'] !== $inputs->environment
            || $row['inputs_digest'] !== $inputs->occurrenceInputsDigest
            || self::micros(RatingRows::text($row['period_start'])) !== $inputs->periodStartMicros
            || self::micros(RatingRows::text($row['period_end'])) !== $inputs->periodEndMicros
            || ($row['state'] === 'rated' && $row['rated_basis_ref'] !== 'rb_'.substr(hash('sha256',
                implode('|', [$inputs->sellerRef, $inputs->environment, $inputs->occurrenceRef, 'original', $inputs->digest()])), 0, 48))) {
            RatingComputation::refuse('occurrence_not_bookable');
        }
    }

    public function advance(TransactionContext $context, FrozenRatingInputs $inputs, string $basis, bool $booked): void
    {
        $this->participants->advanceOccurrence(context: $context, command: new AdvanceOccurrence(
            sellerRef: $inputs->sellerRef, environment: $inputs->environment, occurrenceRef: $inputs->occurrenceRef,
            to: $booked ? OccurrenceState::Obligated : OccurrenceState::Rated, ratedBasisRef: $basis));
    }

    public static function micros(string $instant): int
    {
        $time = new DateTimeImmutable($instant);

        return $time->getTimestamp() * 1000000 + (int) $time->format('u');
    }
}
