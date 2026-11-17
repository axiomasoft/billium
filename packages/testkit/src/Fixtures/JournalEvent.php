<?php

declare(strict_types=1);

namespace Billium\Testkit\Fixtures;

use Packman\Attributes\Api;

/** Test lifecycle event; never a production retirement proof. */
#[Api(since: '1.0')]
final readonly class JournalEvent
{
    /** @param array<string, string|int|bool> $data */
    public function __construct(public string $kind, public array $data, public int $pid) {}
}
