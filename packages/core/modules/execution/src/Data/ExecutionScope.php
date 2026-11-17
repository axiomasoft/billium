<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ExecutionScope
{
    public function __construct(
        public string $installationRef,
        public string $sellerRef,
        public string $environment,
        public string $intentOwnerNamespace,
    ) {
        if ($installationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'installationRef must not be empty');
        }
        if ($sellerRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'sellerRef must not be empty');
        }
        if ($environment === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'environment must not be empty');
        }
        if ($intentOwnerNamespace === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'intentOwnerNamespace must not be empty');
        }
    }
}
