<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\CollectionChannel;
use Billium\Collection\Enums\DeclineCategory;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class DunningPolicyView
{
    /**
     * @param  list<CollectionChannel>  $allowedChannels
     * @param  list<DunningPolicyStepDefinition>  $orderedSteps
     * @param  list<DeclineCategory>  $retryableDeclineCategories
     */
    public function __construct(
        public int $id,
        public string $sellerRef,
        public string $environment,
        public string $policyRef,
        public int $policyVersion,
        public array $allowedChannels,
        public array $orderedSteps,
        public int $maxBusinessDeclineAttempts,
        public int $maxDeliveryRetries,
        public array $retryableDeclineCategories,
        public int $recoveryHorizonSeconds,
        public ?int $graceSignalAfterSeconds,
        public ?int $readOnlySignalAfterSeconds,
        public int $minOutstandingMinor,
        public ?string $thresholdCurrency,
        public ?int $supersedesPolicyId,
        public string $policyDigest,
        public DateTimeImmutable $createdAt,
    ) {}
}
