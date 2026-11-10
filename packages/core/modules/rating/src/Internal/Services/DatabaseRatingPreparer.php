<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Services;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Rating\Contracts\RatingPreparer;
use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Data\Rating\PrepareRating;
use Billium\Rating\Data\Rating\RatingHold;
use Billium\Rating\Enums\HoldReason;
use Billium\Rating\Internal\Pipeline\FrozenInputsBuilder;
use Billium\Rating\Internal\Pipeline\RatingComputation;

final readonly class DatabaseRatingPreparer implements RatingPreparer
{
    public function __construct(private FrozenInputsBuilder $builder, private RatingComputation $computation) {}

    public function prepare(PrepareRating $command): PreparedRating|RatingHold
    {
        try {
            $inputs = $this->builder->build($command);
            if ($inputs instanceof RatingHold) {
                return $inputs;
            }

            return new PreparedRating(inputs: $inputs, draft: $this->computation->compute($inputs));
        } catch (ProblemException $error) {
            return new RatingHold(reason: HoldReason::UnknownPolicy, repairOrReplayRefs: [], detail: $error->detail);
        }
    }
}
