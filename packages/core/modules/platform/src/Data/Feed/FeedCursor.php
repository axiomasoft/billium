<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Feed;

use Packman\Attributes\Api;

/** Only the source authenticates this token; positions are diagnostic, not authority. */
#[Api(since: '1.0')]
final readonly class FeedCursor
{
    public function __construct(public string $token, public FeedPosition $position) {}
}
