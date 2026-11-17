<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ObservationPartition
{
    public function __construct(
        public int $id,
        public ExecutionScope $scope,
        public string $sourceAuthority,
        public string $sourceLineage,
        public string $rootOperationRef,
        public string $partitionRef,
        public int $lastSequence,
    ) {
        if ($sourceAuthority === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'sourceAuthority must not be empty');
        }
        if ($sourceLineage === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'sourceLineage must not be empty');
        }
        if ($rootOperationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'rootOperationRef must not be empty');
        }
        if ($partitionRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'partitionRef must not be empty');
        }
    }
}
