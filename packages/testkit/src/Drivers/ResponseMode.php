<?php

declare(strict_types=1);

namespace Billium\Testkit\Drivers;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum ResponseMode: string
{
    case Success = 'success';
    case Pending = 'pending';
    case Declined = 'declined';
    case AcceptedThenTimeout = 'accepted_then_timeout';
    case Unavailable = 'unavailable';
}
