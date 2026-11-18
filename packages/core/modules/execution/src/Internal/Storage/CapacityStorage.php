<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Storage;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Data\AuthorizationRecord;
use Billium\Execution\Data\CapacitySnapshot;
use Billium\Execution\Data\Reservation;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ReservationState;
use Billium\Execution\Internal\Capacity\CapacityAmounts;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

/** Capacity counters, held reservations and the authorization record of an original root; every write is version-checked under the root lock. */
#[Internal]
final readonly class CapacityStorage
{
    public function __construct(private ExecutionTables $tables) {}

    public function capacity(TransactionContext $transactionContext, string $rootRef): CapacitySnapshot
    {
        $this->tables->financial($transactionContext);
        $this->tables->requireLock($transactionContext, $rootRef);

        return ExecutionRecords::capacity($this->tables->callerQuery('capacity', $transactionContext)->where('root_operation_ref', $rootRef)->first() ?? throw new LogicException('Capacity absent'));
    }

    public function reserveCapacity(TransactionContext $transactionContext, Reservation $reservation, CapacitySnapshot $expected): CapacitySnapshot
    {
        $current = $this->capacity($transactionContext, $reservation->rootOperationRef);
        if (ExecutionCodec::encode($current) !== ExecutionCodec::encode($expected) || $reservation->state !== ReservationState::Held || $reservation->consumed->minor !== 0 || $reservation->requested->minor <= 0 || ExecutionCodec::encode($reservation->requested->currency) !== ExecutionCodec::encode($current->authorizedLimit->currency) || $reservation->requested->minor > CapacityAmounts::available($current, $reservation->kind)) {
            ExecutionTables::refuse('Capacity reservation conflict');
        }
        $this->insertHeldReservation($transactionContext, $reservation, $current);

        return $this->capacity($transactionContext, $reservation->rootOperationRef);
    }

    public function authorization(TransactionContext $transactionContext, string $rootOperationRef): ?AuthorizationRecord
    {
        $this->tables->financial($transactionContext);
        $this->tables->requireLock($transactionContext, $rootOperationRef);
        $r = $this->tables->callerQuery('authorization', $transactionContext)->where('root_operation_ref', $rootOperationRef)->first();
        if ($r === null) {
            return null;
        }

        return ExecutionRecords::authorization($r);
    }

    public function applyAuthorization(TransactionContext $transactionContext, AuthorizationRecord $authorizationRecord, int $expectedRevision): void
    {
        $current = $this->authorization($transactionContext, $authorizationRecord->rootOperationRef);
        if ($authorizationRecord->revision !== $expectedRevision + 1 || ($current === null ? $expectedRevision !== 0 : $current->revision !== $expectedRevision)) {
            ExecutionTables::refuse('Authorization revision conflict');
        }
        $row = ['authorization_ref' => $authorizationRecord->authorizationRef, 'root_operation_ref' => $authorizationRecord->rootOperationRef, 'authorized_minor' => $authorizationRecord->authorized->minor, 'released_minor' => $authorizationRecord->released->minor, 'currency' => $authorizationRecord->authorized->currency->code, 'provider_expires_at' => $authorizationRecord->providerExpiresAt?->format(ExecutionRows::TIMESTAMP), 'expiry_evidence_ref' => $authorizationRecord->expiryEvidenceRef, 'release_evidence_ref' => $authorizationRecord->releaseEvidenceRef, 'state' => $authorizationRecord->state, 'revision' => $authorizationRecord->revision];
        if ($current === null) {
            $this->tables->database($transactionContext)->table('billium_execution_authorization')->insert($this->tables->callerScope($transactionContext) + $row);
        } else {
            unset($row['authorization_ref'],$row['root_operation_ref'],$row['currency']);
            if ($this->tables->callerQuery('authorization', $transactionContext)->where('authorization_ref', $authorizationRecord->authorizationRef)->where('revision', $expectedRevision)->update($row) !== 1) {
                ExecutionTables::refuse('Authorization CAS conflict');
            }
        }
    }

    public function insertHeldReservation(TransactionContext $transactionContext, Reservation $reservation, CapacitySnapshot $expected): void
    {
        $this->tables->database($transactionContext)->table('billium_execution_reservation')->insert($this->tables->callerScope($transactionContext) + ['reservation_ref' => $reservation->reservationRef, 'root_operation_ref' => $reservation->rootOperationRef, 'operation_ref' => $reservation->operationRef, 'kind' => $reservation->kind->value, 'requested_minor' => $reservation->requested->minor, 'currency' => $reservation->requested->currency->code, 'state' => 'held']);
        $column = match ($reservation->kind) {
            EffectKind::Capture => 'held_capture_minor',EffectKind::VoidAuthorization => 'held_void_minor',EffectKind::Refund => 'held_refund_minor',default => throw new LogicException('Invalid reservation kind')
        };
        $updated = $this->tables->callerQuery('capacity', $transactionContext)->where('root_operation_ref', $reservation->rootOperationRef)
            ->where('version', $expected->version)->incrementEach([$column => $reservation->requested->minor, 'version' => 1]);
        if ($updated !== 1) {
            ExecutionTables::refuse('Held reservation capacity changed');
        }
    }

    public function write(TransactionContext $context, CapacitySnapshot $next, int $expectedVersion): void
    {
        $this->tables->requireLock($context, $next->rootOperationRef);
        foreach ([$next->authorizedLimit, $next->confirmedCapture, $next->confirmedRefund, $next->releasedAuthorization, $next->heldCapture, $next->heldVoid, $next->heldRefund] as $money) {
            if ($money->minor < 0 || $money->currency->code !== $next->authorizedLimit->currency->code || $money->currency->scale !== $next->authorizedLimit->currency->scale) {
                ExecutionTables::refuse('Capacity counters require one exact nonnegative currency');
            }
        }
        $row = ['authorized_limit_minor' => $next->authorizedLimit->minor, 'confirmed_capture_minor' => $next->confirmedCapture->minor, 'confirmed_refund_minor' => $next->confirmedRefund->minor, 'released_authorization_minor' => $next->releasedAuthorization->minor, 'held_capture_minor' => $next->heldCapture->minor, 'held_void_minor' => $next->heldVoid->minor, 'held_refund_minor' => $next->heldRefund->minor, 'financial_hold' => $next->financialHold, 'version' => $next->version];
        if ($next->version !== $expectedVersion + 1 || $this->tables->callerQuery('capacity', $context)->where('root_operation_ref', $next->rootOperationRef)->where('version', $expectedVersion)->where('currency', $next->authorizedLimit->currency->code)->update($row) !== 1) {
            ExecutionTables::refuse('Capacity version conflict');
        }
    }

    public function reservation(TransactionContext $context, string $reservationRef): ?Reservation
    {
        $this->tables->financial($context);
        $row = $this->tables->callerQuery('reservation', $context)->where('reservation_ref', $reservationRef)
            ->whereIn('operation_ref', $this->tables->callerQuery('operation', $context)->where('intent_owner_namespace', $this->tables->selectedScope($context)->intentOwnerNamespace)->select('operation_ref'))->first();

        return $row === null ? null : $this->reservationRow($context, $row);
    }

    public function reservationForOperation(TransactionContext $context, string $operationRef): ?Reservation
    {
        $this->tables->financial($context);
        $this->tables->requireOperationLock($context, $operationRef);
        $row = $this->tables->callerQuery('reservation', $context)->where('operation_ref', $operationRef)->first();

        return $row === null ? null : $this->reservationRow($context, $row);
    }

    private function reservationRow(TransactionContext $context, stdClass $row): Reservation
    {
        $this->tables->requireOperationLock($context, ExecutionRows::text($row->operation_ref));

        return ExecutionRecords::reservation($row);
    }
}
