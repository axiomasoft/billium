<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Feed;

use Billium\Contracts\Attributes\Api;

#[Api]
final readonly class RelayReport
{
    public function __construct(public int $claimed, public int $delivered, public int $failed, public int $stale) {}
}
