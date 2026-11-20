<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\CollectionChannel;
use Billium\Collection\Enums\DeclineCategory;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class RegisterDunningPolicyVersion
{
    /**
     * @param  list<CollectionChannel>  $allowedChannels
     * @param  list<DunningPolicyStepDefinition>  $orderedSteps
     * @param  list<DeclineCategory>  $retryableDeclineCategories
     */
    public function __construct(
        public string $commandKey,
        public CollectionScope $scope,
        public string $policyRef,
        public int $policyVersion,
        public array $allowedChannels,
        public array $orderedSteps,
        public int $maxBusinessDeclineAttempts,
        public int $maxDeliveryRetries,
        public array $retryableDeclineCategories,
        public int $recoveryHorizonSeconds,
        public ?int $graceSignalAfterSeconds = null,
        public ?int $readOnlySignalAfterSeconds = null,
        public int $minOutstandingMinor = 0,
        public ?string $thresholdCurrency = null,
        public ?int $supersedesPolicyVersion = null,
    ) {}
}
