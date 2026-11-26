<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Capacity;

use Billium\Execution\Data\CapacitySnapshot;
use Billium\Execution\Enums\EffectKind;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final class CapacityAmounts
{
    public static function available(CapacitySnapshot $capacity, EffectKind $kind): int
    {
        if ($capacity->financialHold) {
            return 0;
        }

        return match ($kind) {
            EffectKind::Capture, EffectKind::VoidAuthorization => self::remaining($capacity->authorizedLimit->minor,
                $capacity->confirmedCapture->minor, $capacity->releasedAuthorization->minor, $capacity->heldCapture->minor, $capacity->heldVoid->minor),
            EffectKind::Refund => self::remaining($capacity->confirmedCapture->minor, $capacity->confirmedRefund->minor, $capacity->heldRefund->minor),
            default => throw new LogicException('Only a child effect can reserve capacity'),
        };
    }

    /** Subtract each nonnegative term separately so adding held minor units cannot overflow. */
    public static function remaining(int $limit, int ...$deductions): int
    {
        foreach ($deductions as $deduction) {
            if ($deduction < 0 || $limit < 0) {
                throw new LogicException('Negative financial capacity component');
            }
            $limit = max(0, $limit - $deduction);
        }

        return $limit;
    }
}
