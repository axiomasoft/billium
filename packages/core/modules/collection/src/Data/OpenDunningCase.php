<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class OpenDunningCase
{
    public function __construct(
        public string $commandKey,
        public CollectionScope $scope,
        public string $caseRef,
        public string $billingAccountRef,
        public string $obligationRef,
        public int $collectionGeneration,
        public string $policyRef,
        public int $policyVersion,
        public string $currency,
        public int $openAmountMinor,
        public ?string $componentRef = null,
        public int $cancellationGeneration = 0,
        public ?DateTimeImmutable $openedAt = null,
    ) {}
}
