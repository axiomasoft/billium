<?php

declare(strict_types=1);

namespace Billium\Execution\Enums\Fiscal;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum FiscalDriverOutcomeState: string
{
    case Submitted = 'submitted';
    case Unknown = 'unknown';
    case Registered = 'registered';
    case RejectedTerminal = 'rejected_terminal';
    case Intervention = 'intervention';

    public function toIntentState(): FiscalIntentState
    {
        return match ($this) {
            self::Submitted => FiscalIntentState::Submitted,
            self::Unknown => FiscalIntentState::Unknown,
            self::Registered => FiscalIntentState::Registered,
            self::RejectedTerminal => FiscalIntentState::RejectedTerminal,
            self::Intervention => FiscalIntentState::Intervention,
        };
    }
}
