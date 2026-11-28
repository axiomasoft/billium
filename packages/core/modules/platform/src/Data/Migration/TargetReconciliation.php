<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Migration;

use Billium\Contracts\Contracts\Codec;
use Billium\Platform\Data\Feed\FeedCursor;
use Billium\Platform\Internal\Migration\MigrationDigest;
use InvalidArgumentException;
use Packman\Attributes\Api;

/** Durable target receipt recorded with the adopted cursor and resolved dispositions. */
#[Api(since: '1.0')]
final readonly class TargetReconciliation
{
    /** @param list<ReconciliationChecksum> $checksums */
    public function __construct(
        public string $receiptRef,
        public string $manifestDigest,
        public string $tailDigest,
        public FeedCursor $adoptedCursor,
        public array $checksums,
        public string $dispositionsDigest,
    ) {
        if (trim($receiptRef) === '' || trim($adoptedCursor->token) === '' || ! MigrationDigest::valid($manifestDigest)
            || ! MigrationDigest::valid($tailDigest) || ! MigrationDigest::valid($dispositionsDigest)) {
            throw new InvalidArgumentException('Incomplete target reconciliation');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['receiptRef' => $this->receiptRef, 'manifestDigest' => $this->manifestDigest, 'tailDigest' => $this->tailDigest,
            'adoptedCursor' => ['token' => $this->adoptedCursor->token, ...$this->adoptedCursor->position->toArray()],
            'perSellerCurrencyChecksums' => array_map(static fn (ReconciliationChecksum $summary): array => $summary->toArray(), $this->checksums),
            'dispositionsDigest' => $this->dispositionsDigest];
    }

    public function digest(): string
    {
        return (new Codec)->digest($this->toArray());
    }
}
