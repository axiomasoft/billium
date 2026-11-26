<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Feed;

use Billium\Contracts\Contracts\Codec;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class FeedBootstrap
{
    /** @param array<string,list<array<string,mixed>>> $snapshots */
    public function __construct(
        public FeedSelection $selection,
        public string $leaseRef,
        public string $snapshotRef,
        public string $snapshotDigest,
        public array $snapshots,
        public FeedCursor $cursor,
        public string $expiresAt,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $body = ['schema' => 'billium.feed.bootstrap.v1', 'subscriptionRef' => $this->selection->subscriptionRef,
            'selectionDigest' => $this->selection->selectionDigest, 'scope' => $this->selection->scope->toArray(),
            'eventContractRevision' => $this->selection->eventContractRevision, 'leaseRef' => $this->leaseRef,
            'snapshotRef' => $this->snapshotRef, 'snapshotDigest' => $this->snapshotDigest,
            'snapshots' => (object) $this->snapshots, 'nextCursor' => $this->cursor->token, 'expiresAt' => $this->expiresAt];

        return [...$body, 'digest' => (new Codec)->digest($body)];
    }
}
