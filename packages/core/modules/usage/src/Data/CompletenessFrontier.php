<?php

declare(strict_types=1);

namespace Billium\Usage\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Usage\Internal\Canonical\CanonicalJson;

#[Api]
final readonly class CompletenessFrontier
{
    public string $digest;

    /** @var list<array<string, mixed>> */
    public array $evidence;

    /** @param list<SourceCompleteness> $evidence */
    public function __construct(public string $contractRef, public int $contractVersion, array $evidence)
    {
        usort($evidence, static fn (SourceCompleteness $a, SourceCompleteness $b): int => strcmp($a->sourceRef, $b->sourceRef) ?: $a->evidenceSeq <=> $b->evidenceSeq);
        $this->evidence = array_map(static fn (SourceCompleteness $e): array => [
            ...$e->payload(), 'evidenceDigest' => $e->evidenceDigest,
        ], $evidence);
        $this->digest = CanonicalJson::digest($this->evidence);
    }
}
