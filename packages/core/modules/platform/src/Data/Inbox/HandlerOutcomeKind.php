<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Inbox;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum HandlerOutcomeKind: string
{
    case Applied = 'applied';
    case ParkedDependency = 'parked_dependency';
    case Conflict = 'conflict';
}
