<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class AuthorizationRecord
{
    public function __construct(
        public string $authorizationRef,
        public string $rootOperationRef,
        public Money $authorized,
        public Money $released,
        public ?DateTimeImmutable $providerExpiresAt,
        public ?string $expiryEvidenceRef,
        public string $state,
        public int $revision,
        public ?string $releaseEvidenceRef = null,
    ) {
        if ($authorizationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'authorizationRef must not be empty');
        }
        if ($rootOperationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'rootOperationRef must not be empty');
        }
        if ($expiryEvidenceRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'expiryEvidenceRef must be null or nonempty');
        }
        if ($releaseEvidenceRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'releaseEvidenceRef must be null or nonempty');
        }
        if ($state === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'state must not be empty');
        }
    }
}
