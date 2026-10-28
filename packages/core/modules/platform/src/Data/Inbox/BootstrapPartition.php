<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Inbox;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Contracts\Codec;
use InvalidArgumentException;

/**
 * A complete source snapshot for one partition at `cursorSequence`, pinned by the source retention lease. The snapshot
 * digest covers the cursor and every item in (operationRef, revision) order, so a partial or altered snapshot never
 * verifies (D60, inbox-feed).
 */
#[Api]
final readonly class BootstrapPartition
{
    /** @var list<BootstrapItem> */
    public array $items;

    /** @param list<BootstrapItem> $items */
    public function __construct(
        public string $bootstrapRef,
        public string $channel,
        public string $sourceAuthority,
        public string $sourceLineage,
        public string $sellerRef,
        public string $environment,
        public string $partitionRef,
        public int $cursorSequence,
        public string $snapshotDigest,
        public string $sourceRetentionLeaseRef,
        public int $expectedItemCount,
        array $items,
    ) {
        foreach ([$bootstrapRef, $channel, $sourceAuthority, $sourceLineage, $sellerRef, $environment, $partitionRef, $sourceRetentionLeaseRef] as $value) {
            if ($value === '' || strlen($value) > 200) {
                throw new InvalidArgumentException('Bootstrap scope fields are nonempty bounded strings');
            }
        }
        if ($cursorSequence < 0 || $expectedItemCount < 0) {
            throw new InvalidArgumentException('Bootstrap cursor and item count are not negative');
        }
        usort($items, static fn (BootstrapItem $a, BootstrapItem $b): int => [$a->operationRef, $a->revision] <=> [$b->operationRef, $b->revision]);
        $this->items = $items;
    }

    /** @param list<BootstrapItem> $items */
    public static function digestOf(int $cursorSequence, array $items): string
    {
        usort($items, static fn (BootstrapItem $a, BootstrapItem $b): int => [$a->operationRef, $a->revision] <=> [$b->operationRef, $b->revision]);
        $body = (object) [
            'cursor' => $cursorSequence,
            'items' => array_map(static fn (BootstrapItem $item): \stdClass => (object) [
                'operationRef' => $item->operationRef, 'revision' => $item->revision,
                'materialDigest' => $item->materialDigest, 'payload' => $item->payload,
            ], $items),
        ];

        return (new Codec)->digest($body);
    }

    /** The snapshot is complete and untampered: declared count, unique items, digest over everything. */
    public function verifies(): bool
    {
        $keys = [];
        foreach ($this->items as $item) {
            $keys[$item->operationRef.'#'.$item->revision] = true;
        }

        return count($this->items) === $this->expectedItemCount && count($keys) === count($this->items)
            && hash_equals($this->snapshotDigest, self::digestOf($this->cursorSequence, $this->items));
    }
}
