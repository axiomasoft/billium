<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Feed;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class RelayReport
{
    public function __construct(public int $claimed, public int $delivered, public int $failed, public int $stale) {}
}
