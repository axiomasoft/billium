<?php

declare(strict_types=1);

namespace Billium\Usage\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Usage\Internal\Errors\UsageProblems;

#[Api]
final readonly class FactRevision
{
    /** @var numeric-string */
    public string $quantityUnscaled;

    /** @param list<string> $memberIds */
    public function __construct(
        public string $factId,
        public int $factRevision,
        public string $factDigest,
        public bool $retracted,
        public int $observedAtMicros,
        public int $receivedAtMicros,
        public int $ingestSeq,
        public string $sourceRef,
        public ?int $sourceSequence,
        string $quantityUnscaled,
        public array $memberIds = [],
    ) {
        if (! is_numeric($quantityUnscaled) || $factId === '' || $factRevision < 1 || preg_match('/^-?(0|[1-9][0-9]*)$/D', $quantityUnscaled) !== 1) {
            throw UsageProblems::error('fact_invalid');
        }
        $this->quantityUnscaled = $quantityUnscaled;
    }
}
