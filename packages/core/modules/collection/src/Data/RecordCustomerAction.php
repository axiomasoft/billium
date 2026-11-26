<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\CustomerActionType;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class RecordCustomerAction
{
    /** @param array<string, mixed> $actionPayload */
    public function __construct(
        public string $commandKey,
        public CollectionScope $scope,
        public string $actionRef,
        public string $billingAccountRef,
        public string $consentRef,
        public int $consentVersion,
        public string $businessPaymentIntentRef,
        public CustomerActionType $actionType,
        public string $challengeRef,
        public array $actionPayload,
        public DateTimeImmutable $expiresAt,
        public ?string $operationRef = null,
    ) {}
}
