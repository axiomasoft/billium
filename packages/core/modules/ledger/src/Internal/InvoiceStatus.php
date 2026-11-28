<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal;

use InvalidArgumentException;
use Packman\Attributes\Internal;

/**
 * Invoice FSM table and the pure reference oracle of the derived payment status (p3-ledger-design invoice-fsm).
 * The guarded DB routines own the authoritative status update in the Tx of the settled change; PHP never writes status
 * or counters and uses this type only to check the table and name the transitions the commands request.
 */
#[Internal]
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Uncollectible = 'uncollectible';
    case Void = 'void';
    case Discarded = 'discarded';

    /**
     * Status after a change of settled = Σ(allocated + credited) of the line obligations. Only issued, partially_paid,
     * paid and uncollectible derive; uncollectible stays while nothing is settled and leaves it when a late payment arrives.
     * draft, void and discarded never derive and keep their status.
     */
    public static function derive(self $current, int $totalMinor, int $settledMinor): self
    {
        if ($totalMinor < 0 || $settledMinor < 0 || $settledMinor > $totalMinor) {
            throw new InvalidArgumentException('settled must lie between 0 and the invoice total');
        }

        return match ($current) {
            self::Draft, self::Void, self::Discarded => $current,
            self::Uncollectible => $settledMinor === 0 ? $current : self::fromSettled($totalMinor, $settledMinor),
            self::Issued, self::PartiallyPaid, self::Paid => self::fromSettled($totalMinor, $settledMinor),
        };
    }

    /** Transitions a trigger-level UPDATE may make (billium_invoice_freeze); same-status is not a transition. */
    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => $next === self::Issued || $next === self::Discarded,
            self::Issued => in_array($next, [self::PartiallyPaid, self::Paid, self::Uncollectible, self::Void], true),
            self::PartiallyPaid => in_array($next, [self::Issued, self::Paid, self::Uncollectible], true),
            self::Paid => $next === self::Issued || $next === self::PartiallyPaid,
            self::Uncollectible => $next === self::PartiallyPaid || $next === self::Paid,
            self::Void, self::Discarded => false,
        };
    }

    /** Frozen after issue: every state but draft. */
    public function isFrozen(): bool
    {
        return $this !== self::Draft;
    }

    private static function fromSettled(int $totalMinor, int $settledMinor): self
    {
        return match (true) {
            $settledMinor === 0 => self::Issued,
            $settledMinor === $totalMinor => self::Paid,
            default => self::PartiallyPaid,
        };
    }
}
