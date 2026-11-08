<?php

declare(strict_types=1);

namespace Billium\Core\Enums;

use Billium\Contracts\Attributes\Api;

/**
 * What a root transaction needs from the financial session barrier (D54, D62).
 *
 * Active and Recovery are financial: the runner takes billium_financial_session FOR SHARE after the claim, verifies the
 * host process token against the adopted session and holds the lock to commit. Administrative takes the same shared
 * barrier without a token and never changes money (module transitions). SessionControl takes no barrier: the body
 * itself locks the session row FOR UPDATE (open/activate/seal). RawCustody records immutable input only and has no
 * financial effect, so it may run after a seal.
 */
#[Api]
enum FinancialMode: string
{
    case Active = 'active';
    case Recovery = 'recovery';
    case Administrative = 'administrative';
    case SessionControl = 'session_control';
    case RawCustody = 'raw_custody';

    public function financial(): bool
    {
        return $this === self::Active || $this === self::Recovery;
    }

    public function sharesSessionBarrier(): bool
    {
        return $this->financial() || $this === self::Administrative;
    }
}
