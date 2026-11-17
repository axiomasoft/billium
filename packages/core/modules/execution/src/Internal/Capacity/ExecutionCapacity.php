<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Capacity;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Contracts\CapacityParticipants;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Data\AbsenceCertificate;
use Billium\Execution\Data\CapacitySnapshot;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\Reservation;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ReservationState;
use Billium\Execution\Internal\Reducer\MaterialRules;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class ExecutionCapacity implements CapacityParticipants
{
    public function __construct(private ExecutionStore $store) {}

    public function current(TransactionContext $transactionContext, string $rootRef): CapacitySnapshot
    {
        return $this->store->capacity($transactionContext, $rootRef);
    }

    public function reserve(TransactionContext $transactionContext, string $rootRef, string $operationRef, EffectKind $effectKind, Money $money, string $reservationRef): Reservation
    {
        $current = $this->current($transactionContext, $rootRef);
        $selection = $transactionContext->spec()->financialScope;
        $caller = $transactionContext->caller();
        $scope = new ExecutionScope($caller->installationRef, $caller->sellerRef, $caller->environment,
            $selection->intentOwnerNamespace ?? throw new LogicException('Child capacity requires selected owner'));
        $child = $this->store->operation($scope, $operationRef, $transactionContext);
        if ($child === null || $child->rootOperationRef !== $rootRef || $child->kind !== $effectKind || $child->requested->minor !== $money->minor
            || ! in_array($effectKind, [EffectKind::Capture, EffectKind::VoidAuthorization, EffectKind::Refund], true)) {
            throw new LogicException('Reservation does not identify its admitted child');
        }

        if ($money->currency->code !== $current->authorizedLimit->currency->code || $money->currency->scale !== $current->authorizedLimit->currency->scale || $money->minor <= 0 || $current->financialHold || $money->minor > CapacityAmounts::available($current, $effectKind)) {
            throw new ProblemException(match ($effectKind) {
                EffectKind::Refund => ProblemCode::RefundCapacityExceeded,
                EffectKind::Capture => ProblemCode::CaptureCapacityExceeded,
                default => ProblemCode::AuthorizationCapacityExceeded,
            }, 'Requested amount exceeds available original capacity');
        }
        $reservation = new Reservation($reservationRef, $rootRef, $operationRef, $effectKind, $money, ReservationState::Held, new Money(0, $money->currency), null);
        $this->store->reserveCapacity($transactionContext, $reservation, $current);

        return $reservation;
    }

    public function settle(TransactionContext $transactionContext, string $reservationRef, MaterialSnapshot $materialSnapshot, ?AbsenceCertificate $absenceCertificate): CapacitySnapshot
    {
        $reservation = $this->store->reservation($transactionContext, $reservationRef) ?? throw new LogicException('Original child reservation is missing');
        $fact = $this->store->factForEvidence($transactionContext, $materialSnapshot->evidenceRefs[0] ?? '', $reservation->operationRef);
        if ($absenceCertificate !== null || $fact === null || ExecutionCodec::encode($fact->snapshot) !== ExecutionCodec::encode($materialSnapshot)
            || $reservation->kind !== $materialSnapshot->kind || $materialSnapshot->operationRef !== $reservation->operationRef) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Reservation requires the original authenticated provider fact');
        }
        $current = $this->current($transactionContext, $reservation->rootOperationRef);
        if (in_array($materialSnapshot->normalizedStatus, ['pending', 'unknown'], true)) {
            return $current;
        }
        $amount = $materialSnapshot->operationAmount;
        $success = $materialSnapshot->normalizedStatus === 'succeeded';
        if ((! $success && ! in_array($materialSnapshot->normalizedStatus, ['declined', 'failed', 'no_effect'], true))
            || $amount === null || $amount->currency->code !== $reservation->requested->currency->code || $amount->currency->scale !== $reservation->requested->currency->scale || $amount->minor < 0 || $amount->minor > $reservation->requested->minor
            || $success !== ($amount->minor > 0) || $reservation->state !== ReservationState::Held || $current->financialHold) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Reservation has conflicting settlement evidence');
        }
        $scope = $transactionContext->spec()->financialScope;
        $caller = $transactionContext->caller();
        $selection = new ExecutionScope($caller->installationRef, $caller->sellerRef, $caller->environment, $scope->intentOwnerNamespace ?? throw new LogicException('Missing capacity owner'));
        $operation = $this->store->operation($selection, $reservation->operationRef, $transactionContext) ?? throw new LogicException('Missing original child');
        $root = $this->store->operation($selection, $reservation->rootOperationRef, $transactionContext) ?? throw new LogicException('Missing original root');
        [$reason] = MaterialRules::bounds($materialSnapshot, $operation, $root, $current, $reservation);
        if ($reason !== null || MaterialRules::invalid($materialSnapshot, $operation) !== null) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Settlement would exceed verified original capacity');
        }
        $next = self::withoutHold($current, $reservation);
        $frontier = $this->store->latestRootObservation($transactionContext, $reservation->rootOperationRef);
        $order = MaterialRules::order($materialSnapshot, null, $frontier);
        if ($order !== null && $order !== 'stale') {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Inconsistent provider cumulative frontier');
        }
        if ($order !== 'stale') {
            $next = new CapacitySnapshot($next->rootOperationRef, $next->version, $materialSnapshot->authorizedTotal, $materialSnapshot->capturedTotal,
                $materialSnapshot->refundedTotal, $materialSnapshot->voidedTotal, $next->heldCapture, $next->heldVoid, $next->heldRefund, false);
        }
        $this->store->settleReservation($transactionContext, $reservationRef, $success ? ReservationState::Consumed : ReservationState::Released,
            $amount, $materialSnapshot->evidenceRefs[0], $next);

        return $next;
    }

    public static function withoutHold(CapacitySnapshot $current, Reservation $reservation): CapacitySnapshot
    {
        $column = match ($reservation->kind) {
            EffectKind::Capture => 'heldCapture', EffectKind::Refund => 'heldRefund', EffectKind::VoidAuthorization => 'heldVoid',
            default => throw new LogicException('Unsupported child reservation kind'),
        };
        $held = $current->{$column};
        if ($reservation->rootOperationRef !== $current->rootOperationRef || $reservation->state !== ReservationState::Held
            || $held->currency->code !== $reservation->requested->currency->code || $held->currency->scale !== $reservation->requested->currency->scale || $held->minor < $reservation->requested->minor) {
            throw new LogicException('Reservation differs from original held capacity');
        }

        $remaining = new Money($held->minor - $reservation->requested->minor, $held->currency);

        return new CapacitySnapshot($current->rootOperationRef, $current->version + 1, $current->authorizedLimit, $current->confirmedCapture,
            $current->confirmedRefund, $current->releasedAuthorization, $column === 'heldCapture' ? $remaining : $current->heldCapture,
            $column === 'heldVoid' ? $remaining : $current->heldVoid, $column === 'heldRefund' ? $remaining : $current->heldRefund, $current->financialHold);
    }
}
