<?php

declare(strict_types=1);

namespace Billium\Testkit\Coordinator;

use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Durable provisioning attempt receipt owned by the host ProductCoordinator double.
 */
#[Api(since: '1.0')]
final readonly class ProvisioningAttemptReceipt
{
    public function __construct(
        public string $receiptRef,
        public string $paymentRef,
        public string $componentRef,
        public string $grantKey,
        public int $attemptNo,
        public bool $succeeded,
        public ?string $failureReason,
        public bool $retryProvisionOnly,
        public bool $chargeTriggered,
        public DateTimeImmutable $recordedAt,
    ) {}
}
