<?php

declare(strict_types=1);

namespace Billium\Usage\Data;

use Billium\Contracts\Attributes\Api;
use stdClass;

/** D56: trusted host evidence; mappingRef is the immutable host mapping revision, not a live account alias. */
#[Api]
final readonly class AttributionSnapshot
{
    public function __construct(
        public string $sellerRef,
        public stdClass $attribution,
        public string $mappingRef,
        public string $validFrom,
        public string $validUntil,
        public string $periodRef,
        public string $periodStart,
        public string $periodEnd,
    ) {}
}
