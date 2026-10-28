<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Feed;

use Billium\Platform\Internal\Installation\Rows;

final readonly class PartitionRow
{
    public function __construct(
        public int $id,
        public string $channel,
        public string $sourceAuthority,
        public string $sourceLineage,
        public string $sellerRef,
        public string $environment,
        public string $partitionRef,
        public int $generation,
        public int $custodySequence,
        public int $accountedSequence,
        public string $state,
    ) {}

    public static function from(mixed $row): self
    {
        return new self(
            Rows::int(Rows::field($row, 'id')), Rows::string(Rows::field($row, 'channel')), Rows::string(Rows::field($row, 'source_authority')),
            Rows::string(Rows::field($row, 'source_lineage')), Rows::string(Rows::field($row, 'seller_ref')), Rows::string(Rows::field($row, 'environment')),
            Rows::string(Rows::field($row, 'partition_ref')), Rows::int(Rows::field($row, 'generation')), Rows::int(Rows::field($row, 'custody_sequence')),
            Rows::int(Rows::field($row, 'accounted_sequence')), Rows::string(Rows::field($row, 'state')),
        );
    }

    public function active(): bool
    {
        return $this->state === 'active';
    }
}
