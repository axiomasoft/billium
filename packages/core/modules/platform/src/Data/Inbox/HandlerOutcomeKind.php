<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Inbox;

use Billium\Contracts\Attributes\Api;

#[Api]
enum HandlerOutcomeKind: string
{
    case Applied = 'applied';
    case ParkedDependency = 'parked_dependency';
    case Conflict = 'conflict';
}
