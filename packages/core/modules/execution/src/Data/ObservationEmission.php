<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ObservationEmission
{
    public function __construct(
        public string $emissionRef,
        public ExecutionScope $scope,
        public string $sourceAuthority,
        public string $sourceLineage,
        public string $partitionRef,
        public int $sourceSequence,
        public string $operationRef,
        public int $operationRevision,
        public string $commandRef,
        public string $eventId,
        public string $payloadDigest,
        public PaymentObservation $payload,
        public ?int $commandRowId,
    ) {
        if ($emissionRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'emissionRef must not be empty');
        }
        if ($sourceAuthority === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'sourceAuthority must not be empty');
        }
        if ($sourceLineage === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'sourceLineage must not be empty');
        }
        if ($partitionRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'partitionRef must not be empty');
        }
        if ($operationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'operationRef must not be empty');
        }
        if ($commandRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'commandRef must not be empty');
        }
        if ($eventId === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'eventId must not be empty');
        }
        if ($payloadDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'payloadDigest must not be empty');
        }
    }
}
