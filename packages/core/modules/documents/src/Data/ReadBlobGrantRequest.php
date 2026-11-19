<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ReadBlobGrantRequest
{
    public function __construct(
        public string $sellerRef,
        public string $environment,
        public string $blobRef,
        public string $actorRef,
        public int $ttlSeconds,
    ) {}
}
