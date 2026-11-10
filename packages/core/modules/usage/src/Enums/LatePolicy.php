<?php

declare(strict_types=1);

namespace Billium\Usage\Enums;

enum LatePolicy: string
{
    case AcceptAdjust = 'accept_adjust';
    case Reject = 'reject';
    case ManualReview = 'manual_review';
}
