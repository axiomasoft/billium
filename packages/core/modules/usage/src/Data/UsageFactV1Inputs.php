<?php

declare(strict_types=1);

namespace Billium\Usage\Data;

use Packman\Attributes\Api;
use stdClass;

#[Api(since: '1.0')]
final readonly class UsageFactV1Inputs
{
    /** @param list<stdClass> $grouping */
    public function __construct(
        public AttributionSnapshot $snapshot,
        public string $sourceDigest,
        public stdClass $value,
        public array $grouping,
        public stdClass $billableStream,
        public ?string $correctionRef = null,
    ) {}
}
