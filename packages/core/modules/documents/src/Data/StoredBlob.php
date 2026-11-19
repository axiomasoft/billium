<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class StoredBlob
{
    public function __construct(
        public string $blobRef,
        public string $outputDigest,
        public string $contentType,
        public int $byteSize,
        public bool $replayed = false,
    ) {}
}
