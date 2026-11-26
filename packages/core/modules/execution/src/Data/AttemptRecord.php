<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Enums\AttemptState;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class AttemptRecord
{
    public function __construct(
        public int $id,
        public string $attemptRef,
        public string $operationRef,
        public string $providerReference,
        public AttemptState $state,
        public int $leaseFence,
        public ?string $leaseOwner,
        public ?DateTimeImmutable $leaseExpiresAt,
        public ?int $sessionEpoch,
        public ?string $ownerSessionRef,
        public ?string $dispatchPermitRef,
        public string $immutableRequestDigest,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $requestDeadlineAt,
        public ?string $collectionDispatchPermitRef,
    ) {
        if ($attemptRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'attemptRef must not be empty');
        }
        if ($operationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'operationRef must not be empty');
        }
        if ($providerReference === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'providerReference must not be empty');
        }
        if ($leaseOwner === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'leaseOwner must be null or nonempty');
        }
        if ($ownerSessionRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'ownerSessionRef must be null or nonempty');
        }
        if ($dispatchPermitRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'dispatchPermitRef must be null or nonempty');
        }
    }
}
