<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class StoreRenderBlob
{
    public function __construct(
        public string $sellerRef,
        public string $environment,
        public string $uploadKey,
        public string $contentType,
        public string $bytes,
        public string $outputDigest,
        public ?DateTimeImmutable $retentionPinnedUntil = null,
    ) {}
}
