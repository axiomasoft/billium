<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\NotificationChannel;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class CollectionNotificationMessage
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $messageId,
        public string $sellerRef,
        public string $environment,
        public string $caseRef,
        public ?string $stepRef,
        public int $stepNo,
        public string $templateRef,
        public string $templateRevision,
        public NotificationChannel $channel,
        public string $recipientRef,
        public array $payload,
        public string $payloadDigest,
        public int $attemptNo,
    ) {}
}
