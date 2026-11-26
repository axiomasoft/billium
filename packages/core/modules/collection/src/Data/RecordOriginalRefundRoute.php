<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class RecordOriginalRefundRoute
{
    public function __construct(
        public string $commandKey,
        public CollectionScope $scope,
        public string $operationRef,
        public string $billingAccountRef,
        public string $consentRef,
        public int $consentVersion,
    ) {}
}
