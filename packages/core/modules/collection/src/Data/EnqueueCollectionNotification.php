<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\NotificationChannel;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class EnqueueCollectionNotification
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $commandKey,
        public CollectionScope $scope,
        public string $caseRef,
        public ?string $stepRef,
        public string $templateRef,
        public string $templateRevision,
        public NotificationChannel $channel,
        public string $recipientRef,
        public array $payload = [],
        public ?DateTimeImmutable $enqueuedAt = null,
    ) {}
}
