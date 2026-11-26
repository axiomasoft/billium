<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Packman\Attributes\Api;

/** Authenticated seller and environment scope for seller-partitioned documents and series. */
#[Api(since: '1.0')]
final readonly class DocumentScope
{
    public function __construct(
        public string $sellerRef,
        public string $environment,
    ) {}
}
