<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Enums\EffectKind;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class OperationRecord
{
    public function __construct(
        public int $id,
        public string $operationRef,
        public string $rootOperationRef,
        public EffectKind $kind,
        public FrozenBinding $binding,
        public Money $requested,
        public int $revision,
        public string $state,
        public string $economicDigest,
        public ?string $authorizationRef,
        public DateTimeImmutable $createdAt,
    ) {
        if ($operationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'operationRef must not be empty');
        }
        if ($rootOperationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'rootOperationRef must not be empty');
        }
        if ($state === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'state must not be empty');
        }
        if ($economicDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'economicDigest must not be empty');
        }
        if ($authorizationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'authorizationRef must be null or nonempty');
        }
    }
}
