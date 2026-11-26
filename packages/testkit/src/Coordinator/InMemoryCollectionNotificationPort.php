<?php

declare(strict_types=1);

namespace Billium\Testkit\Coordinator;

use Billium\Collection\Contracts\CollectionNotificationPort;
use Billium\Collection\Data\CollectionNotificationMessage;
use Billium\Collection\Data\NotificationTransportAck;
use DateTimeImmutable;
use DateTimeZone;
use Override;
use Packman\Attributes\Api;
use RuntimeException;

/**
 * Deterministic in-memory CollectionNotificationPort test double for P7.6 acceptance tests.
 *
 * Records transport delivery attempts and returns transport-only ACKs without ever mutating
 * payment, step, or dunning case outcomes.
 */
#[Api(since: '1.0')]
final class InMemoryCollectionNotificationPort implements CollectionNotificationPort
{
    /** @var list<CollectionNotificationMessage> */
    private array $deliveries = [];

    /** @var array<string, list<NotificationTransportAck>> */
    private array $acksByMessage = [];

    private int $sequence = 0;

    private bool $offline = false;

    private ?string $failNextError = null;

    private bool $forceInvalidAckFlag = false;

    public function setOffline(bool $offline): void
    {
        $this->offline = $offline;
    }

    public function failNextWith(string $message): void
    {
        $this->failNextError = $message;
    }

    public function setForceInvalidAckFlag(bool $invalid): void
    {
        $this->forceInvalidAckFlag = $invalid;
    }

    #[Override]
    public function deliver(CollectionNotificationMessage $message): NotificationTransportAck
    {
        $this->deliveries[] = $message;

        if ($this->offline) {
            throw new RuntimeException('notification_transport_offline');
        }
        if ($this->failNextError !== null) {
            $err = $this->failNextError;
            $this->failNextError = null;

            throw new RuntimeException($err);
        }

        $this->sequence++;
        $ack = new NotificationTransportAck(
            messageId: $message->messageId,
            transportAckRef: sprintf('transport-ack:%d:%s', $this->sequence, substr(hash('sha256', $message->messageId), 0, 12)),
            acknowledgedAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            transportDeliveryOnly: ! $this->forceInvalidAckFlag,
        );
        $this->acksByMessage[$message->messageId][] = $ack;

        return $ack;
    }

    /** @return list<CollectionNotificationMessage> */
    public function deliveries(): array
    {
        return $this->deliveries;
    }

    public function deliveryCount(?string $messageId = null): int
    {
        if ($messageId === null) {
            return count($this->deliveries);
        }

        $count = 0;
        foreach ($this->deliveries as $delivery) {
            if ($delivery->messageId === $messageId) {
                $count++;
            }
        }

        return $count;
    }

    /** @return list<NotificationTransportAck> */
    public function acksFor(string $messageId): array
    {
        return $this->acksByMessage[$messageId] ?? [];
    }

    /** Transport notification delivery NEVER mutates business or payment outcomes. */
    public function paymentOutcomeMutations(): int
    {
        return 0;
    }
}
