<?php

declare(strict_types=1);

namespace Billium\Collection\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum ManualOverrideAction: string
{
    case PauseCase = 'pause_case';
    case ResumeCase = 'resume_case';
    case RescheduleNextAttempt = 'reschedule_next_attempt';
    case CancelCase = 'cancel_case';
    case ForgePaymentSuccess = 'forge_payment_success';
    case ClearUnknownOutcome = 'clear_unknown_outcome';
}
