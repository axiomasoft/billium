<?php

declare(strict_types=1);

namespace Billium\Usage\Enums;

use Billium\Contracts\Attributes\Api;

#[Api]
enum LatePolicy: string
{
    case AcceptAdjust = 'accept_adjust';
    case Reject = 'reject';
    case ManualReview = 'manual_review';
}
