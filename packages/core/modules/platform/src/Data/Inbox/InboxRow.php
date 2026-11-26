<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Inbox;

use Packman\Attributes\Api;

/** Durable custody row of one authenticated delivery, as the handler sees it (content immutable, status moves). */
#[Api(since: '1.0')]
final readonly class InboxRow
{
    public function __construct(
        public int $id,
        public string $channel,
        public string $sourceAuthority,
        public string $sourceLineage,
        public string $sellerRef,
        public string $environment,
        public string $externalEventId,
        public string $contentDigest,
        public ?int $partitionId,
        public string $partitionRef,
        public int $sourceSequence,
        public string $operationRef,
        public int $operationRevision,
        public string $status,
        public int $attempts,
        public ?string $resultRef,
    ) {}
}
