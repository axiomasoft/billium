<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Data\Envelopes\AuthorizePayment;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class AdmissionConflict
{
    public function __construct(
        public ExecutionScope $scope,
        public string $commandKind,
        public string $commandKeyDigest,
        public string $semanticDigest,
        public ?string $originalOperationRef,
    ) {
        if (! in_array($commandKind, [ExecutePayment::SCHEMA, AuthorizePayment::SCHEMA], true)
            || preg_match('/^sha256:[0-9a-f]{64}$/D', $commandKeyDigest) !== 1 || preg_match('/^sha256:[0-9a-f]{64}$/D', $semanticDigest) !== 1
            || $originalOperationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid attempted root payment conflict');
        }
    }
}
