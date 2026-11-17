<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ReservationState;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class Reservation
{
    public function __construct(
        public string $reservationRef,
        public string $rootOperationRef,
        public string $operationRef,
        public EffectKind $kind,
        public Money $requested,
        public ReservationState $state,
        public Money $consumed,
        public ?string $releaseEvidenceRef,
    ) {
        if ($reservationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'reservationRef must not be empty');
        }
        if ($rootOperationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'rootOperationRef must not be empty');
        }
        if ($operationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'operationRef must not be empty');
        }
        if ($releaseEvidenceRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'releaseEvidenceRef must be null or nonempty');
        }
    }
}
