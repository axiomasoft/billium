<?php

declare(strict_types=1);

namespace Billium\Execution\Enums\Fiscal;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum FiscalIntentState: string
{
    case Prepared = 'prepared';
    case Submitted = 'submitted';
    case Unknown = 'unknown';
    case Registered = 'registered';
    case RejectedTerminal = 'rejected_terminal';
    case Intervention = 'intervention';

    public function isTerminal(): bool
    {
        return $this === self::Registered || $this === self::RejectedTerminal;
    }

    public function allowsLookup(): bool
    {
        return $this === self::Submitted || $this === self::Unknown;
    }
}
