<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Inbox;

use Billium\Contracts\Enums\ProblemCode;
use Packman\Attributes\Api;

/** Outcome of one custody step. Only `Applied` and a replayed `Duplicate` of an applied row carry a business receipt. */
#[Api(since: '1.0')]
enum CustodyDisposition: string
{
    /** Durable custody only: in order but not applied (raw-custody root, or a financial root has not drained it yet). */
    case Received = 'received';
    case Applied = 'applied';
    case Duplicate = 'duplicate';
    case ParkedGap = 'parked_gap';
    case ParkedDependency = 'parked_dependency';
    /** The handler refused the delivery; it is quarantined and the source order advanced. */
    case Quarantined = 'quarantined';
    /** The same event identity arrived with another content digest; the original is untouched. */
    case Conflict = 'conflict';
    case RecoveryRequired = 'recovery_required';
    /** A drain found nothing at the next accounted sequence. */
    case Idle = 'idle';

    public function problem(): ?ProblemCode
    {
        return match ($this) {
            self::ParkedGap, self::ParkedDependency => ProblemCode::ObservationParked,
            self::Quarantined, self::Conflict => ProblemCode::ObservationConflict,
            self::RecoveryRequired => ProblemCode::RecoveryRequired,
            default => null,
        };
    }
}
