<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class RecordStepDeliveryRetry
{
    public function __construct(
        public string $commandKey,
        public CollectionScope $scope,
        public string $caseRef,
        public string $stepRef,
        public int $expectedStepRevision,
        public DateTimeImmutable $attemptedAt,
        public ?string $failureReason = null,
    ) {}
}
