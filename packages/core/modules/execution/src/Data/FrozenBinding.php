<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class FrozenBinding
{
    public function __construct(
        public ExecutionScope $scope,
        public string $executionProfile,
        public string $businessIntentRef,
        public string $rootOperationRef,
        public string $merchantRevisionRef,
        public string $merchantAccountRef,
        public string $driverId,
        public string $driverContractVersion,
        public string $capabilityDigest,
        public string $semanticDigest,
        public string $canonicalizationVersion,
        public ?string $collectionAuthorizationRef,
        public string $sourceContext,
        public ?OriginDecision $origin = null,
    ) {
        if ($origin !== null && (get_object_vars($origin->scope) !== get_object_vars($scope) || $origin->operationRef !== $rootOperationRef
            || $origin->businessIntentRef !== $businessIntentRef || $origin->sourceContext !== $sourceContext
            || $origin->executionProfile !== $executionProfile || $origin->authorizationRef !== $collectionAuthorizationRef)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Verified origin differs from the original root binding');
        }
        if (! in_array($executionProfile, ['remote-billium', 'standalone-billium'], true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Unsupported Billium executionProfile');
        }
        if ($sourceContext === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'sourceContext must not be empty');
        }
        if ($businessIntentRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'businessIntentRef must not be empty');
        }
        if ($rootOperationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'rootOperationRef must not be empty');
        }
        if ($merchantRevisionRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'merchantRevisionRef must not be empty');
        }
        if ($merchantAccountRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'merchantAccountRef must not be empty');
        }
        if ($driverId === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'driverId must not be empty');
        }
        if ($driverContractVersion === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'driverContractVersion must not be empty');
        }
        if ($capabilityDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'capabilityDigest must not be empty');
        }
        if ($semanticDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'semanticDigest must not be empty');
        }
        if ($canonicalizationVersion === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'canonicalizationVersion must not be empty');
        }
        if ($collectionAuthorizationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'collectionAuthorizationRef must be null or nonempty');
        }
    }
}
