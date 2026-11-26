<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class CollectionScope
{
    public function __construct(
        public string $sellerRef,
        public string $environment,
    ) {}
}
