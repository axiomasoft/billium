<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Repair;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ProviderFact;
use Billium\Execution\Data\RecoveryAction;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ReservationState;
use Billium\Execution\Internal\Disputes\ComparisonBasis;
use Billium\Execution\Internal\Reconciliation\ReconciliationJournal;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class LinkedCorrectionApproval
{
    public function __construct(private ExecutionStore $store) {}

    /**
     * Approve the local application of a refund already included in the original provider statement.
     * At admission the refund source names the stable statement reference as its reasonCode and
     * reconciliation_correction as its category. Both terms precede dispatch and remain immutable.
     *
     * @param  array<string,string>  $route
     * @param  list<ProviderFact>  $facts
     * @return list<string>
     */
    public function links(TransactionContext $ctx, Connection $db, array $route, RecoveryAction $action, stdClass $review, array $facts): array
    {
        if (count($facts) !== 1 || $review->code !== 'balance_mismatch' || $review->provenance !== 'original_provider_inventory') {
            $this->refuse();
        }
        $statement = $action->evidenceRefs[0];
        $evidence = $action->evidenceRefs[1];
        $fact = $facts[0];
        $s = $fact->snapshot;
        $root = $this->store->operation($action->scope, $action->rootOperationRef, $ctx);
        $child = $this->store->operation($action->scope, $fact->operationRef, $ctx);
        if ($root === null || $child === null || $child->rootOperationRef !== $root->operationRef || $child->operationRef === $root->operationRef
            || $child->kind !== EffectKind::Refund || $child->state !== 'succeeded' || $s->kind !== EffectKind::Refund || $s->normalizedStatus !== 'succeeded'
            || $s->operationRef !== $child->operationRef || $s->currency !== $review->currency || $s->operationAmount === null || $s->operationAmount->minor <= 0) {
            $this->refuse();
        }
        $basis = ExecutionCodec::map(ReconciliationJournal::string($review->expected));
        $observed = ExecutionCodec::map(ReconciliationJournal::string($review->observed));
        $observedAt = $observed['observedAt'] ?? null;
        $priorRefund = $basis['refundedMinor'] ?? null;
        $total = $observed['minor'] ?? null;
        $capacity = $this->store->capacity($ctx, $root->operationRef);
        if (($observed['kind'] ?? null) !== 'balance_comparison' || ($observed['factRef'] ?? null) !== $statement
            || ($observed['currency'] ?? null) !== $s->currency || ($observed['sourceDigest'] ?? null) !== $review->source_digest
            || ! is_string($observedAt) || ! is_int($priorRefund) || ! is_int($total) || $total - $priorRefund !== $s->operationAmount->minor
            || $s->observedAt > new DateTimeImmutable($observedAt) || $capacity->financialHold || $capacity->confirmedRefund->minor !== $total
            || $s->refundedTotal->minor !== $total || $s->capturedTotal->minor !== $capacity->confirmedCapture->minor
            || $capacity->confirmedCapture->minor !== ($basis['capturedMinor'] ?? null) || ($basis['financialHold'] ?? null) !== false
            || ($basis['rootRef'] ?? null) !== $root->operationRef || ($basis['currency'] ?? null) !== $s->currency || ($basis['status'] ?? null) !== $root->state
            || ExecutionCodec::digest($basis) !== $review->basis_digest) {
            $this->refuse();
        }
        $item = $db->table('billium_execution_recon_item')->where(['review_ref' => $review->event_ref, 'root_ref' => $root->operationRef,
            'kind' => 'balance_comparison', 'disposition' => 'exposure', 'evidence_ref' => $statement, 'source_digest' => $review->source_digest])
            ->whereRaw('comparison_basis = ?::jsonb AND observed = ?::jsonb', [$review->expected, $review->observed])->exists();
        $custody = $db->table('billium_execution_provider_evidence')->where($route)->where('evidence_ref', $evidence)
            ->where('received_at', '<=', $observedAt)->exists();
        $identity = $db->table('billium_execution_child_identity')->where($route)->where(['operation_kind' => 'refund', 'operation_ref' => $child->operationRef,
            'root_operation_ref' => $root->operationRef, 'intent_owner_namespace' => $action->scope->intentOwnerNamespace, 'source_context' => $root->binding->sourceContext,
            'reason_code' => $statement, 'category' => 'reconciliation_correction', 'currency' => $s->currency])->first(['receipt_ref', 'semantic_digest']);
        $reservation = $this->store->reservationForOperation($ctx, $child->operationRef);
        if (! $item || ! $custody || $identity === null || $reservation?->state !== ReservationState::Consumed || $reservation->kind !== EffectKind::Refund
            || $reservation->rootOperationRef !== $root->operationRef || $reservation->consumed->minor !== $s->operationAmount->minor || $reservation->releaseEvidenceRef !== $evidence) {
            $this->refuse();
        }
        $revisions = $db->table('billium_execution_operation_revision')->where($route);
        $applied = (clone $revisions)->where('operation_ref', $child->operationRef)->where('revision', $child->revision)
            ->where('material_digest', $child->economicDigest)->whereJsonContains('evidence_refs', $evidence)
            ->whereRaw('observed = ?::jsonb', [ExecutionCodec::encode($s)])->value('receipt_ref');
        if (! is_string($applied)) {
            $this->refuse();
        }
        $rootRevision = $basis['rootRevision'] ?? null;
        if (! is_int($rootRevision) || ($rootRevision !== $root->revision && ($rootRevision + 1 !== $root->revision
            || ! (clone $revisions)->where('operation_ref', $root->operationRef)->where('revision', $root->revision)->where('receipt_ref', $applied)
                ->where('material_digest', $root->economicDigest)->whereJsonContains('evidence_refs', $evidence)->exists()))) {
            $this->refuse();
        }
        // Rewind only the local revisions written by this work. Every sibling stays at its locked current revision.
        $contributors = ComparisonBasis::contributors($db, $root);
        foreach ($contributors as $row) {
            if ($row->operation_ref === $child->operationRef) {
                $this->precedingRevision($db, $route, $row, $child, ReconciliationJournal::string($identity->semantic_digest));
            } elseif ($row->operation_ref === $root->operationRef && $rootRevision !== $root->revision) {
                $this->precedingRevision($db, $route, $row, $root, $root->binding->semanticDigest);
            }
        }
        if (ExecutionCodec::digest($contributors) !== ($basis['contributingRevisionsDigest'] ?? null)) {
            $this->refuse();
        }

        return [ReconciliationJournal::string($review->case_ref), $root->operationRef, $child->operationRef, ReconciliationJournal::string($identity->receipt_ref), $applied, $fact->factRef];
    }

    /** @param array<string,string> $route */
    private function precedingRevision(Connection $db, array $route, stdClass $row, OperationRecord $operation, string $admissionDigest): void
    {
        $revision = $operation->revision - 1;
        $digest = $revision === 0 ? $admissionDigest : $db->table('billium_execution_operation_revision')->where($route)
            ->where('operation_ref', $operation->operationRef)->where('revision', $revision)->value('material_digest');
        if (! is_string($digest)) {
            $this->refuse();
        }
        $row->revision = $revision;
        $row->economic_digest = $digest;
    }

    private function refuse(): never
    {
        throw new ProblemException(ProblemCode::RecoveryRequired, 'Correction approval requires the original statement and the applied refund that accounts for its difference');
    }
}
