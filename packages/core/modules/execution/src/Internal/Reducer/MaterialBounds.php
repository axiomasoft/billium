<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Reducer;

use Billium\Contracts\Data\Money;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Data\CapacitySnapshot;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\Reservation;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ReservationState;
use Billium\Execution\Internal\Capacity\CapacityAmounts;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class MaterialBounds
{
    public function __construct(private OperationRunner $runner, private DatabaseManager $databases) {}

    /** @return array{?string,Money,Money} */
    public function evaluate(TransactionContext $ctx, MaterialSnapshot $snapshot, OperationRecord $op, OperationRecord $root, CapacitySnapshot $capacity, ?Reservation $reservation, bool $olderRoot = false): array
    {
        $limit = min($root->requested->minor, $snapshot->authorizedTotal->minor);
        $capture = new Money(max(0, $snapshot->capturedTotal->minor - CapacityAmounts::remaining($limit, $snapshot->voidedTotal->minor)), $root->requested->currency);
        $refund = new Money(max(0, $snapshot->refundedTotal->minor - $snapshot->capturedTotal->minor), $root->requested->currency);
        $positive = MaterialRules::state($snapshot) === 'succeeded';
        $heldCapture = $capacity->heldCapture->minor;
        $heldVoid = $capacity->heldVoid->minor;
        $heldRefund = $capacity->heldRefund->minor;
        if ($reservation?->state === ReservationState::Held && in_array(MaterialRules::state($snapshot), ['succeeded', 'failed'], true)) {
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
        [$covered, $contradiction] = $this->reservationEvidence($ctx, $op, $root, $reservation, $snapshot,
            ['capture' => $observedCapture, 'refund' => $observedRefund, 'void_authorization' => $observedVoid]);
        $heldRefund -= $covered['refund'] ?? 0;
        $heldCapture -= $covered['capture'] ?? 0;
        $heldVoid -= $covered['void_authorization'] ?? 0;
        $reason = match (true) {
            $refund->minor > 0 => 'over_refund', $capture->minor > 0 => 'over_capture',
            $positive && (in_array($op->state, ['failed', 'cancelled'], true) || $reservation?->state === ReservationState::Released) => 'late_positive_after_no_effect',
            $snapshot->authorizedTotal->minor > $root->requested->minor => 'authorization_exceeds_request',
            $snapshot->voidedTotal->minor > $snapshot->authorizedTotal->minor => 'excess_release',
            $snapshot->operationAmount !== null && $snapshot->operationAmount->minor > $op->requested->minor => 'operation_amount_exceeds_request',
            $op->rootOperationRef !== $op->operationRef && $reservation === null => 'missing_original_reservation',
            $contradiction => 'known_child_effects_exceed_cumulative',
            $heldRefund > CapacityAmounts::remaining($observedCapture, $observedRefund) => 'refund_reservation_overlap',
            $heldCapture > CapacityAmounts::remaining($observedLimit, $observedCapture, $observedVoid)
                || $heldVoid > CapacityAmounts::remaining($observedLimit, $observedCapture, $observedVoid, $heldCapture) => 'authorization_reservation_overlap',
            $capacity->financialHold => 'unresolved_financial_hold', default => null,
        };

        return [$reason, $capture, $refund];
    }

    /**
     * Only the original child fact can release its reservation. Under the locked root, an imported,
     * send-closed sibling can explain at most the part of the cumulative observation still unattributed.
     * This calculation changes neither the held balance nor the amount available for a new command.
     *
     * @param  array<string,int>  $observed
     * @return array{array<string,int>,bool}
     */
    private function reservationEvidence(TransactionContext $ctx, OperationRecord $op, OperationRecord $root, ?Reservation $reservation,
        MaterialSnapshot $snapshot, array $observed): array
    {
        $isRoot = $op->operationRef === $root->operationRef;
        if ($snapshot->operationAmount === null || ! in_array(MaterialRules::state($snapshot), ['succeeded', 'failed'], true)) {
            return [[], false];
        }
        $this->runner->assertCurrent($ctx, 'execution');
        $binding = $root->binding;
        $scope = $binding->scope;
        $spec = $ctx->spec();
        $allowHistorical = $spec->mode === FinancialMode::Recovery && $spec->financialScope !== null && ($isRoot || $reservation?->state === ReservationState::Held)
            && get_object_vars($spec->financialScope) === get_object_vars(PaymentIdentity::select($ctx->caller(), $scope, $binding->executionProfile));
        if ($reservation !== null) {
            // A prior settlement already contributes to the persisted lower bound for this exact child.
            $observed[$reservation->kind->value] -= max(0, $snapshot->operationAmount->minor - $reservation->consumed->minor);
        }

        // Known child actuals must fit the provider cumulative in every financial mode.
        // The immutable import identifies an independently authenticated dispatch and commercial source.
        // For a child, its own import must share the retired lineage. For a root report, its qualified
        // original partition anchors each eligible lineage. New or unrelated reservations count in full.
        $rows = $this->databases->connection($ctx->connectionName())->select(<<<'SQL'
            WITH originals AS (
                SELECT i.operation_ref, i.original_lineage
                FROM billium_execution_original_identity_import i
                JOIN billium_execution_operation o USING (installation_ref,seller_ref,environment,intent_owner_namespace,operation_ref)
                JOIN billium_execution_attempt a USING (installation_ref,seller_ref,environment,operation_ref,attempt_ref)
                JOIN billium_execution_namespace n USING (installation_ref,seller_ref,environment,intent_owner_namespace)
                JOIN billium_execution_observation_partition p ON (p.installation_ref,p.seller_ref,p.environment,p.root_operation_ref,p.source_authority,p.source_lineage)
                    = (i.installation_ref,i.seller_ref,i.environment,o.root_operation_ref,?,i.original_lineage)
                WHERE ?::boolean AND (i.installation_ref,i.seller_ref,i.environment,i.intent_owner_namespace,o.root_operation_ref,o.merchant_account_ref,o.binding_digest)
                    = (?,?,?,?,?,?,?)
                  AND n.state IN ('closed','recovery_required') AND i.adopted_epoch<=?
                  AND a.merchant_account_ref=o.merchant_account_ref AND a.started_at IS NOT NULL AND a.owner_session_ref IS NOT NULL
                  AND a.lease_fence>i.original_fence AND a.lease_owner IS NULL AND a.lease_expires_at IS NULL
                  AND NOT EXISTS (SELECT 1 FROM billium_execution_dispatch_work w WHERE
                      (w.installation_ref,w.seller_ref,w.environment,w.operation_ref)=(i.installation_ref,i.seller_ref,i.environment,i.operation_ref))
            ), cohort AS (
                SELECT operation_ref FROM originals i WHERE ?::boolean
                    OR EXISTS (SELECT 1 FROM originals subject WHERE subject.operation_ref=? AND subject.original_lineage=i.original_lineage)
            ), amounts AS (
                SELECT r.kind, sum(r.consumed_minor) AS known,
                    coalesce(sum(r.requested_minor-r.consumed_minor) FILTER
                        (WHERE r.state='held' AND r.operation_ref<>? AND c.operation_ref IS NOT NULL),0) AS historical,
                    CASE r.kind WHEN 'capture' THEN ?::numeric WHEN 'refund' THEN ?::numeric ELSE ?::numeric END AS budget
                FROM billium_execution_reservation r LEFT JOIN cohort c USING (operation_ref)
                WHERE (r.installation_ref,r.seller_ref,r.environment,r.root_operation_ref)=(?,?,?,?)
                GROUP BY r.kind
            )
            SELECT kind, least(historical,greatest(0,budget-known))::bigint AS covered, known>budget AS contradiction FROM amounts
            SQL, [
            $binding->executionProfile, $allowHistorical, $scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace,
            $root->operationRef, $binding->merchantAccountRef, ExecutionCodec::bindingDigest($binding), $ctx->sessionEpoch(),
            $isRoot, $op->operationRef, $op->operationRef, $observed['capture'], $observed['refund'], $observed['void_authorization'],
            $scope->installationRef, $scope->sellerRef, $scope->environment, $root->operationRef,
        ]);
        $covered = [];
        $contradiction = false;
        foreach ($rows as $row) {
            if (! $row instanceof stdClass || ! is_string($row->kind) || ! is_int($row->covered) || ! is_bool($row->contradiction)) {
                throw new LogicException('Invalid historical reservation aggregate');
            }
            $covered[$row->kind] = $row->covered;
            $contradiction = $contradiction || $row->contradiction;
        }

        return [$covered, $contradiction];
    }
}
