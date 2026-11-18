<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Reducer;

use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Enums\EffectKind;
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

    /** Classify revisions of the same operation independently of transport delivery order. */
    public static function operationOrder(MaterialSnapshot $next, ?MaterialSnapshot $prior): ?string
    {
        if ($prior === null) {
            return null;
        }
        $order = self::compareRevision($next->providerRevision, $prior->providerRevision);
        if ($order === -1) {
            if (! self::lessOrEqual($next, $prior)) {
                return 'older_revision_increases_totals';
            }
            if (self::terminalRegression($next, $prior) || (self::state($next) === 'failed' && self::state($prior) === 'succeeded')) {
                return 'terminal_status_conflict';
            }

            return 'stale';
        }
        if (self::digest($next) === self::digest($prior)) {
            return 'duplicate';
        }
        if ($order === 0) {
            return 'provider_revision_conflict';
        }
        if (self::terminalRegression($prior, $next)) {
            return 'terminal_status_conflict';
        }

        return null;
    }

    public static function cumulativeOrder(MaterialSnapshot $next, ?MaterialSnapshot $frontier): ?string
    {
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

    private static function terminalRegression(MaterialSnapshot $older, MaterialSnapshot $newer): bool
    {
        $before = self::state($older);
        $after = self::state($newer);

        return ($before === 'succeeded' && $after !== 'succeeded')
            || ($before === 'failed' && in_array($after, ['pending', 'unknown'], true));
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
}
