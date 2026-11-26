<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ProviderWorkClaim
{
    public function __construct(
        public string $workRef,
        public string $evidenceRef,
        public string $handlerContractVersion,
        public int $attemptCount,
        public int $leaseFence,
        public string $leaseOwner,
        public DateTimeImmutable $leaseExpiresAt,
    ) {
        if ($workRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'workRef must not be empty');
        }
        if ($evidenceRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'evidenceRef must not be empty');
        }
        if ($handlerContractVersion === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'handlerContractVersion must not be empty');
        }
        if ($leaseOwner === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'leaseOwner must not be empty');
        }
    }
}
