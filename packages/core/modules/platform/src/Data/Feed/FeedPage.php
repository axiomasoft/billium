<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Feed;

use Billium\Contracts\Contracts\Codec;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class FeedPage
{
    /** @param list<array<string,mixed>> $events */
    public function __construct(
        public string $subscriptionRef,
        public array $events,
        public FeedCursor $nextCursor,
        public string $selectionDigest,
        public bool $hasMore,
        public FeedPosition $replayFloor,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $body = ['schema' => 'billium.feed.page.v1', 'subscriptionRef' => $this->subscriptionRef, 'events' => $this->events,
            'nextCursor' => $this->nextCursor->token, 'scannedThrough' => $this->nextCursor->position->toArray(),
            'selectionDigest' => $this->selectionDigest, 'hasMore' => $this->hasMore, 'replayFloor' => $this->replayFloor->toArray()];

        return [...$body, 'digest' => (new Codec)->digest($body)];
    }
}
