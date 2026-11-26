<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class CollectionGuardReceipt
{
    public function __construct(
        public string $authorizationRef,
        public int $guardRevision,
        public string $consentVersion,
        public int $cancellationGeneration,
        public string $businessDigest,
        public string $operationRef,
        public bool $permitted,
        public ?string $reason,
    ) {
        if ($authorizationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'authorizationRef must not be empty');
        }
        if ($consentVersion === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'consentVersion must not be empty');
        }
        if ($businessDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'businessDigest must not be empty');
        }
        if ($operationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'operationRef must not be empty');
        }
        if ($reason === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'reason must be null or nonempty');
        }
    }
}
