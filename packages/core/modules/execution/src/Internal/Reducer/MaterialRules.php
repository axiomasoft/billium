<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Reducer;

use Billium\Contracts\Data\Money;
use Billium\Execution\Data\CapacitySnapshot;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\Reservation;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ReservationState;
use Billium\Execution\Internal\Capacity\CapacityAmounts;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Packman\Attributes\Internal;

#[Internal]
final class MaterialRules
{
    public static function digest(MaterialSnapshot $snapshot): string
    {
        return PaymentIdentity::digest(['schema' => 'billium.execution.material.v1', 'operation' => $snapshot->operationRef,
            'kind' => $snapshot->kind->value, 'account' => $snapshot->merchantAccountRef, 'currency' => $snapshot->currency,
            'authorized' => $snapshot->authorizedTotal->toWire(), 'captured' => $snapshot->capturedTotal->toWire(), 'voided' => $snapshot->voidedTotal->toWire(),
            'refunded' => $snapshot->refundedTotal->toWire(), 'operationAmount' => $snapshot->operationAmount?->toWire(), 'state' => self::state($snapshot),
            ...($snapshot->authorizationExpiresAt === null ? [] : ['authorizationExpiresAt' => $snapshot->authorizationExpiresAt->format('U.u')])]);
    }

    public static function sameEffect(MaterialSnapshot $left, MaterialSnapshot $right): bool
    {
        return $left->operationRef === $right->operationRef && $left->kind === $right->kind
            && $left->stableEffectId === $right->stableEffectId && self::state($left) === self::state($right)
            && $left->operationAmount?->minor === $right->operationAmount?->minor && $left->currency === $right->currency;
    }

    public static function state(MaterialSnapshot $snapshot): string
    {
        return match ($snapshot->normalizedStatus) {
            'declined', 'failed', 'no_effect' => 'failed', default => $snapshot->normalizedStatus,
        };
    }

    public static function invalid(MaterialSnapshot $snapshot, OperationRecord $op): ?string
    {
        $state = self::state($snapshot);
        if (! in_array($state, ['pending', 'unknown', 'succeeded', 'failed'], true)) {
            return 'unsupported_provider_status';
        }
        foreach ([$snapshot->authorizedTotal, $snapshot->capturedTotal, $snapshot->refundedTotal, $snapshot->voidedTotal, $snapshot->operationAmount] as $amount) {
            if ($amount !== null && ($amount->minor < 0 || $amount->currency->code !== $op->requested->currency->code || $amount->currency->scale !== $op->requested->currency->scale || $snapshot->currency !== $amount->currency->code)) {
                return 'invalid_currency_or_amount';
            }
        }
        $amount = $snapshot->operationAmount;
        if (in_array($state, ['succeeded', 'failed'], true) && ($amount === null || ($state === 'succeeded') !== ($amount->minor > 0))) {
            return 'unverified_actual_amount';
        }
        $effectTotal = match ($op->kind) {
            EffectKind::Authorize => $snapshot->authorizedTotal, EffectKind::Capture, EffectKind::Charge => $snapshot->capturedTotal,
            EffectKind::Refund => $snapshot->refundedTotal, EffectKind::VoidAuthorization => $snapshot->voidedTotal,
        };
        if ($amount !== null && $amount->minor > $effectTotal->minor) {
            return 'effect_exceeds_reported_cumulative';
        }

        return null;
    }

    /** Classify cumulative observations without considering transport arrival time or receipt identity. */
    public static function order(MaterialSnapshot $next, ?MaterialSnapshot $prior, ?MaterialSnapshot $frontier): ?string
    {
        if ($prior !== null) {
            $order = self::compareRevision($next->providerRevision, $prior->providerRevision);
            if ($order === 0 && self::digest($next) !== self::digest($prior)) {
                return 'provider_revision_conflict';
            }
            if ($order === -1) {
                return self::lessOrEqual($next, $prior) ? 'stale' : 'older_revision_increases_totals';
            }
            if (self::digest($next) === self::digest($prior)) {
                return 'duplicate';
            }
            if ((self::state($prior) === 'succeeded' && self::state($next) !== 'succeeded') || (self::state($prior) === 'failed' && in_array(self::state($next), ['pending', 'unknown'], true))) {
                return 'terminal_status_conflict';
            }
        }
        if ($frontier === null) {
            return null;
        }
        $order = self::compareRevision($next->providerRevision, $frontier->providerRevision);
        if ($order === -1) {
            return self::lessOrEqual($next, $frontier) ? 'stale' : 'older_revision_increases_totals';
        }
        if (! self::lessOrEqual($frontier, $next)) {
            return 'cumulative_regression';
        }
        if ($order === 0 && ! self::lessOrEqual($next, $frontier)) {
            return 'provider_revision_conflict';
        }

        return null;
    }

    private static function compareRevision(?string $next, ?string $prior): ?int
    {
        if ($next === null || $prior === null) {
            return null;
        }
        if ($next === $prior) {
            return 0;
        }
        if (preg_match('/^(0|[1-9][0-9]*)$/D', $next) !== 1 || preg_match('/^(0|[1-9][0-9]*)$/D', $prior) !== 1) {
            return null;
        }

        $length = strlen($next) <=> strlen($prior);

        return $length === 0 ? strcmp($next, $prior) <=> 0 : $length;
    }

    private static function lessOrEqual(MaterialSnapshot $older, MaterialSnapshot $newer): bool
    {
        return $older->authorizedTotal->minor <= $newer->authorizedTotal->minor && $older->capturedTotal->minor <= $newer->capturedTotal->minor
            && $older->refundedTotal->minor <= $newer->refundedTotal->minor && $older->voidedTotal->minor <= $newer->voidedTotal->minor;
    }

    /** @return array{?string,Money,Money} */
    public static function bounds(MaterialSnapshot $snapshot, OperationRecord $op, OperationRecord $root, CapacitySnapshot $capacity, ?Reservation $reservation, bool $olderRoot = false): array
    {
        $limit = min($root->requested->minor, $snapshot->authorizedTotal->minor);
        $capture = new Money(max(0, $snapshot->capturedTotal->minor - CapacityAmounts::remaining($limit, $snapshot->voidedTotal->minor)), $root->requested->currency);
        $refund = new Money(max(0, $snapshot->refundedTotal->minor - $snapshot->capturedTotal->minor), $root->requested->currency);
        $positive = self::state($snapshot) === 'succeeded';
        $heldCapture = $capacity->heldCapture->minor;
        $heldVoid = $capacity->heldVoid->minor;
        $heldRefund = $capacity->heldRefund->minor;
        if ($reservation?->state === ReservationState::Held && in_array(self::state($snapshot), ['succeeded', 'failed'], true)) {
            $ownRemaining = $reservation->requested->minor - $reservation->consumed->minor;
            match ($reservation->kind) {
                EffectKind::Capture => $heldCapture -= $ownRemaining,
                EffectKind::VoidAuthorization => $heldVoid -= $ownRemaining,
                EffectKind::Refund => $heldRefund -= $ownRemaining,
                default => null,
            };
        }
        $observedCapture = $olderRoot ? $capacity->confirmedCapture->minor : $snapshot->capturedTotal->minor;
        $observedRefund = $olderRoot ? $capacity->confirmedRefund->minor : $snapshot->refundedTotal->minor;
        $observedVoid = $olderRoot ? $capacity->releasedAuthorization->minor : $snapshot->voidedTotal->minor;
        $observedLimit = $olderRoot ? $capacity->authorizedLimit->minor : $limit;
        $reason = match (true) {
            $refund->minor > 0 => 'over_refund', $capture->minor > 0 => 'over_capture',
            $positive && (in_array($op->state, ['failed', 'cancelled'], true) || $reservation?->state === ReservationState::Released) => 'late_positive_after_no_effect',
            $snapshot->authorizedTotal->minor > $root->requested->minor => 'authorization_exceeds_request',
            $snapshot->voidedTotal->minor > $snapshot->authorizedTotal->minor => 'excess_release',
            $snapshot->operationAmount !== null && $snapshot->operationAmount->minor > $op->requested->minor => 'operation_amount_exceeds_request',
            $op->rootOperationRef !== $op->operationRef && $reservation === null => 'missing_original_reservation',
            $heldRefund > CapacityAmounts::remaining($observedCapture, $observedRefund) => 'refund_reservation_overlap',
            $heldCapture > CapacityAmounts::remaining($observedLimit, $observedCapture, $observedVoid)
                || $heldVoid > CapacityAmounts::remaining($observedLimit, $observedCapture, $observedVoid, $heldCapture) => 'authorization_reservation_overlap',
            $capacity->financialHold => 'unresolved_financial_hold', default => null,
        };

        return [$reason, $capture, $refund];
    }
}
