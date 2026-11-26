<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Api;

/** Verified original commercial decision. Null authorization selects direct execution. Null period selects nonperiodic execution. */
#[Api(since: '1.0')]
final readonly class OriginDecision
{
    public function __construct(
        public ExecutionScope $scope,
        public string $operationRef,
        public string $businessIntentRef,
        public string $sourceContext,
        public string $executionProfile,
        public int $routingEpoch,
        public ?string $authorizationRef,
        public string $basisDigest,
        public string $policyVersion,
        public ?string $periodRef,
    ) {
        foreach ([$operationRef, $businessIntentRef, $sourceContext, $basisDigest, $policyVersion, $authorizationRef, $periodRef] as $field) {
            if ($field !== null && trim($field) === '') {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Verified origin has an empty identity or commercial basis');
            }
        }
        if ($routingEpoch < 0 || $routingEpoch > 9007199254740991 || ! in_array($executionProfile, ['remote-billium', 'standalone-billium'], true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid original routing epoch or execution profile');
        }
    }
}
