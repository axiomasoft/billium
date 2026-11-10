<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Attributes\Api;

#[Api]
final readonly class VerifiedCallback
{
    public function __construct(
        public string $eventId,
        public string $merchantAccountRef,
        public string $environment,
        public ProviderEvidence $evidence,
    ) {}
}
