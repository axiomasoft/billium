<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class DispatchDecision
{
    public function __construct(
        public string $operationRef,
        public string $attemptRef,
        public int $leaseFence,
        public int $sessionEpoch,
        public string $ticketRef,
        public string $egressFenceRef,
        public ?string $collectionDispatchPermitRef,
        public string $immutableRequestDigest,
    ) {
        if ($operationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'operationRef must not be empty');
        }
        if ($attemptRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'attemptRef must not be empty');
        }
        if ($ticketRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'ticketRef must not be empty');
        }
        if ($egressFenceRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'egressFenceRef must not be empty');
        }
        if ($collectionDispatchPermitRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'collectionDispatchPermitRef must be null or nonempty');
        }
        if ($immutableRequestDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'immutableRequestDigest must not be empty');
        }
    }
}
