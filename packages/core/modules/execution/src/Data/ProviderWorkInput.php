<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ProviderWorkInput
{
    public function __construct(
        public ProviderEvidence $evidence,
        public string $handlerVersion,
        public string $state,
        public ?string $operationHint,
        public ?string $resultRef,
        public ?string $errorCode,
    ) {}
}
