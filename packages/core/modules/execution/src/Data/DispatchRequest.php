<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Enums\EffectKind;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class DispatchRequest
{
    public function __construct(
        public ExecutionScope $scope,
        public string $operationRef,
        public string $attemptRef,
        public string $providerReference,
        public EffectKind $kind,
        public Money $amount,
        public string $bindingDigest,
        public string $capabilityDigest,
        public string $ownerSessionRef,
        public int $localSessionEpoch,
        public int $authorityEpoch,
        public int $deploymentGeneration,
        public int $attachmentGeneration,
        public int $leaseFence,
        public string $audience,
    ) {
        if ($operationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'operationRef must not be empty');
        }
        if ($attemptRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'attemptRef must not be empty');
        }
        if ($providerReference === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'providerReference must not be empty');
        }
        if ($bindingDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'bindingDigest must not be empty');
        }
        if ($capabilityDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'capabilityDigest must not be empty');
        }
        if ($ownerSessionRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'ownerSessionRef must not be empty');
        }
        if ($audience === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'audience must not be empty');
        }
    }
}
