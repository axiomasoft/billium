<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Migration;

use Billium\Contracts\Contracts\Codec;
use Billium\Platform\Data\Feed\FeedCursor;
use InvalidArgumentException;
use Packman\Attributes\Api;

/** D52/D63: source-attested retained scan from the manifest checkpoint to the sealed terminal cursor. */
#[Api(since: '1.0')]
final readonly class SealedTail
{
    public function __construct(public string $manifestDigest, public FeedCursor $terminalCursor, public string $eventsDigest)
    {
        if (! MigrationDigest::valid($manifestDigest) || ! MigrationDigest::valid($eventsDigest) || trim($terminalCursor->token) === '') {
            throw new InvalidArgumentException('Incomplete tail evidence');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['manifestDigest' => $this->manifestDigest, 'eventsDigest' => $this->eventsDigest,
            'terminalCursor' => ['token' => $this->terminalCursor->token, ...$this->terminalCursor->position->toArray()]];
    }

    public function digest(): string
    {
        return (new Codec)->digest($this->toArray());
    }
}
