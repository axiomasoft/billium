<?php

declare(strict_types=1);

namespace Billium\Server\Data;

use Packman\Attributes\Api;

/** Authorizes ordinary intake on one exact lineage and a set of partitions. Bootstrap uses core recovery authorization. */
#[Api(since: '1.0')]
final readonly class SourceAuthorityGrant
{
    /** @param list<string> $partitionRefs */
    public function __construct(
        public string $channel,
        public string $sourceAuthority,
        public string $installationRef,
        public string $environment,
        public string $sellerRef,
        public string $sourceLineage,
        public array $partitionRefs,
    ) {}
}
