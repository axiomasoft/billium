<?php

declare(strict_types=1);

namespace Billium\Collection\Contracts;

use Billium\Collection\Data\CollectionNotificationView;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\EnqueueCollectionNotification;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Durable deduplicated notification outbox service keyed by (case, step, template revision, channel).
 */
#[Api(since: '1.0')]
interface CollectionNotificationService
{
    public function enqueue(EnqueueCollectionNotification $command): CollectionNotificationView;

    /**
     * Dispatches an enqueued notification through CollectionNotificationPort strictly outside any DB transaction
     * and records its transport delivery ACK or delivery error via CAS. Never mutates payment, step, or case outcome.
     */
    public function dispatch(
        CollectionScope $scope,
        string $messageId,
        string $deliveryCommandKey,
        ?DateTimeImmutable $attemptedAt = null,
    ): CollectionNotificationView;
}
