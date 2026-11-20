<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\RecurringOwnerMode;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class RegisterRecurringOwner
{
    public function __construct(
        public string $commandKey,
        public CollectionScope $scope,
        public string $agreementRef,
        public string $componentRef,
        public RecurringOwnerMode $ownerMode,
        public string $ownerRef,
    ) {}
}
