<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Feed;

use Billium\Contracts\Contracts\Codec;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class FeedSelection
{
    /**
     * @param  list<string>  $envelopeSchemas
     * @param  list<string>  $eventTypes
     */
    public function __construct(
        public string $subscriptionRef,
        public FeedScope $scope,
        public string $eventContractRevision,
        public array $envelopeSchemas,
        public array $eventTypes,
        public string $selectionDigest,
        public FeedCursor $cursor,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $body = ['schema' => 'billium.feed.selection.v1', 'subscriptionRef' => $this->subscriptionRef,
            'scope' => $this->scope->toArray(), 'eventContractRevision' => $this->eventContractRevision,
            'envelopeSchemas' => $this->envelopeSchemas, 'eventTypes' => $this->eventTypes,
            'selectionDigest' => $this->selectionDigest, 'nextCursor' => $this->cursor->token];

        return [...$body, 'digest' => (new Codec)->digest($body)];
    }
}
