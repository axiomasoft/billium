<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Feed;

use Billium\Contracts\Contracts\Codec;
use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class FeedScope
{
    public function __construct(
        public string $installationRef,
        public string $sellerRef,
        public string $environment,
        public string $lineage,
        public string $channel = 'events',
        public string $sourceAuthority = 'billium',
    ) {
        foreach ($this->toArray() as $value) {
            if ($value === '' || strlen($value) > 200) {
                throw new InvalidArgumentException('Feed source scope must be complete and bounded');
            }
        }
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return ['installationRef' => $this->installationRef, 'channel' => $this->channel, 'sourceAuthority' => $this->sourceAuthority,
            'sellerRef' => $this->sellerRef, 'environment' => $this->environment, 'lineage' => $this->lineage];
    }

    public function key(): string
    {
        return (new Codec)->canonicalize($this->toArray());
    }
}
