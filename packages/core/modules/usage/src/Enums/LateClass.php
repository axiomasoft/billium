<?php

declare(strict_types=1);

namespace Billium\Usage\Enums;

use Billium\Contracts\Attributes\Api;

#[Api]
enum LateClass: string
{
    case OpenWindow = 'open_window';
    case InGrace = 'in_grace';
    case PostFinal = 'post_final';
}
