<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class RenderedArtifact
{
    /**
     * @param  list<string>  $assetRefs
     * @param  array<string, mixed>  $diagnostics
     */
    public function __construct(
        public string $contentType,
        public string $bytes,
        public string $outputDigest,
        public array $assetRefs = [],
        public array $diagnostics = [],
    ) {}
}
