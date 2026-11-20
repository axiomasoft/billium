<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class MigrateDunningCasePolicy
{
    public function __construct(
        public string $commandKey,
        public CollectionScope $scope,
        public string $migrationRef,
        public string $caseRef,
        public string $targetPolicyRef,
        public int $targetPolicyVersion,
        public int $expectedCaseRevision,
        public string $reason,
    ) {}
}
