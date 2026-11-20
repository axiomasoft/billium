<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\CustomerActionStatus;
use Billium\Collection\Enums\CustomerActionType;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Customer-action receipt/projection for requires_action responses (never paid and never terminal failure).
 */
#[Api(since: '1.0')]
final readonly class CustomerActionReceipt
{
    /** @param array<string, mixed> $actionPayload */
    public function __construct(
        public int $id,
        public string $sellerRef,
        public string $environment,
        public string $actionRef,
        public string $billingAccountRef,
        public int $consentBindingId,
        public string $consentRef,
        public int $consentVersion,
        public ?string $operationRef,
        public string $businessPaymentIntentRef,
        public CustomerActionType $actionType,
        public CustomerActionStatus $status,
        public string $challengeRef,
        public array $actionPayload,
        public DateTimeImmutable $expiresAt,
        public string $receiptDigest,
        public DateTimeImmutable $recordedAt,
        public bool $paid = false,
        public bool $terminalFailure = false,
    ) {}
}
