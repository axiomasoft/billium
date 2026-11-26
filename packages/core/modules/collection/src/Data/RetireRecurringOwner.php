<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class RetireRecurringOwner
{
    public function __construct(
        public string $commandKey,
        public CollectionScope $scope,
        public string $componentRef,
        public int $expectedOwnerRevision,
        public string $reason,
    ) {}
}
