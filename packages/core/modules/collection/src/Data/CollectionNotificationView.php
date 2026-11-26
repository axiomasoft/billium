<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\NotificationChannel;
use Billium\Collection\Enums\NotificationStatus;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class CollectionNotificationView
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $id,
        public string $sellerRef,
        public string $environment,
        public string $messageId,
        public int $dunningCaseId,
        public string $caseRef,
        public ?int $stepId,
        public ?string $stepRef,
        public int $stepNo,
        public string $templateRef,
        public string $templateRevision,
        public NotificationChannel $channel,
        public string $recipientRef,
        public array $payload,
        public string $payloadDigest,
        public NotificationStatus $status,
        public int $notificationRevision,
        public int $deliveryAttempts,
        public ?string $transportAckRef,
        public ?DateTimeImmutable $transportAckedAt,
        public ?string $lastDeliveryError,
        public ?DateTimeImmutable $lastAttemptAt,
        public DateTimeImmutable $enqueuedAt,
        public DateTimeImmutable $updatedAt,
        public bool $paymentOutcomeChanged = false,
    ) {}
}
