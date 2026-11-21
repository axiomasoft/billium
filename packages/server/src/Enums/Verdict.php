<?php

declare(strict_types=1);

namespace Billium\Server\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum Verdict
{
    case Allow;
    case Deny;
}
