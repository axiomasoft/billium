<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class SecretRef
{
    public function __construct(
        public string $locator,
    ) {
        if ($locator === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'locator must not be empty');
        }
        if (preg_match('~^secret://[a-zA-Z0-9][a-zA-Z0-9._-]*/[a-zA-Z0-9/_-]+$~D', $locator) !== 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'SecretRef requires an opaque secret:// locator without credential material');
        }
    }
}
