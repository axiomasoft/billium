<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Execution\Data\ExecutionScope;
use Packman\Attributes\Api;

/** PHP owner command for an executable collection step (D56, D63/F04). */
#[Api(since: '1.0')]
final readonly class AuthorizeCollection
{
    public function __construct(
        public PlanCollectionStep $step,
        public ExecutionScope $executionScope,
        public string $consentRef,
        public int $consentVersion,
        public int $routingEpoch,
        public string $executionProfile = 'standalone-billium',
    ) {}
}
