<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class LookupRequest
{
    public function __construct(
        public FrozenBinding $binding,
        public string $operationRef,
        public string $providerReference,
        public ?string $providerOperationId,
        public ResolvedCredentials $credentials,
    ) {
        if ($operationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'operationRef must not be empty');
        }
        if ($providerReference === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'providerReference must not be empty');
        }
        if ($providerOperationId === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'providerOperationId must be null or nonempty');
        }
    }
}
