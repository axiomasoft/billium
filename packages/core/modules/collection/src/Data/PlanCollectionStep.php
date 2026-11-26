<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class PlanCollectionStep
{
    public function __construct(
        public string $commandKey,
        public CollectionScope $scope,
        public string $caseRef,
        public string $stepRef,
        public int $stepNo,
        public string $businessPaymentIntentRef,
        public string $operationRef,
        public int $amountMinor,
        public DateTimeImmutable $scheduledAt,
        public ?int $expectedCaseRevision = null,
        public ?string $stepAuthorizationRef = null,
    ) {}
}
