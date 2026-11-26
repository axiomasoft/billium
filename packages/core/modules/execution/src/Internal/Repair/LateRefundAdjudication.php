<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Repair;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Data\ProviderFact;
use Billium\Execution\Data\RecoveryAction;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ReservationState;
use Billium\Execution\Internal\Disputes\ComparisonBasis;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Billium\Execution\Internal\Storage\ExecutionRows;
use Illuminate\Database\Connection;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class LateRefundAdjudication
{
    public function __construct(private ExecutionStore $store) {}

    /**
     * Close the comparison case by identifying the released and subsequently paid refunds.
     * The financial hold and exposure remain in force pending a separate monetary correction.
     *
     * @param  array<string,string>  $route
     * @param  list<ProviderFact>  $replacementFacts
     * @return list<string>
     */
    public function links(TransactionContext $ctx, Connection $db, array $route, RecoveryAction $action, stdClass $review, array $replacementFacts): array
    {
        $root = $action->rootOperationRef;
        $evidence = $action->evidenceRefs[0];
        $work = $db->table('billium_execution_recon_item')->where(['review_ref' => $review->event_ref, 'evidence_ref' => $evidence,
            'root_ref' => $root, 'kind' => 'refund', 'disposition' => 'held_discrepancy', 'source_digest' => $review->source_digest])->value('work_ref');
        $input = is_string($work) ? $this->store->providerWork($work, $ctx) : null;
        if ($review->provenance !== 'original_provider_inventory' || $input === null || $input->state !== 'processed' || $input->resultRef === null
            || $input->evidence->evidenceRef !== $evidence || $input->evidence->payloadDigest !== $review->source_digest
            || $input->operationHint === null || $input->evidence->merchantAccountRef !== $review->merchant_account_ref
            || get_object_vars($input->evidence->scope) !== get_object_vars($action->scope)) {
            $this->refuse();
        }
        $op = $input->operationHint;
        $fact = $this->store->factForEvidence($ctx, $evidence, $op);
        $late = $fact?->snapshot;
        $frontier = $this->store->latestRootObservation($ctx, $root);
        $capacity = $this->store->capacity($ctx, $root);
        if ($fact === null || $late === null || $late->kind !== EffectKind::Refund || $late->normalizedStatus !== 'succeeded'
            || $late->operationAmount === null || $late->operationAmount->minor <= 0 || $late->currency !== $review->currency
            || $late->refundedTotal->minor <= $late->capturedTotal->minor || $late->capturedTotal->minor !== $capacity->confirmedCapture->minor
            || $late->refundedTotal->minor - $late->operationAmount->minor !== $capacity->confirmedRefund->minor
            || ! $capacity->financialHold || ExecutionCodec::differs($frontier, $late)) {
            $this->refuse();
        }
        $discrepancy = $db->table('billium_execution_discrepancy')->where($route)->where(['discrepancy_ref' => $input->resultRef,
            'root_operation_ref' => $root, 'operation_ref' => $op, 'exposure->reason' => 'over_refund'])->whereJsonContains('evidence_refs', $evidence)
            ->where('exposure->overRefunded->minor', $late->refundedTotal->minor - $late->capturedTotal->minor)->exists();
        $original = $this->store->operation($action->scope, $root, $ctx);
        if (! $discrepancy || $original?->state !== 'held' || ExecutionCodec::digest(ComparisonBasis::locked($db, $original, $capacity)) !== $review->basis_digest
            || ExecutionCodec::digest(ExecutionCodec::map(ExecutionRows::text($review->expected))) !== $review->basis_digest) {
            $this->refuse();
        }
        $released = $this->store->reservationForOperation($ctx, $op);
        if ($released?->state !== ReservationState::Released || $released->rootOperationRef !== $root
            || $released->releaseEvidenceRef === null || $released->consumed->minor !== 0) {
            $this->refuse();
        }
        $release = $this->store->factForEvidence($ctx, $released->releaseEvidenceRef, $op)?->snapshot;
        $terminal = $release?->kind === EffectKind::Refund && $release->operationAmount?->minor === 0
            && in_array($release->normalizedStatus, ['failed', 'declined', 'no_effect'], true);
        if (! $terminal && ! $db->table('billium_execution_certified_absence')->where($route)->where('evidence_ref', $released->releaseEvidenceRef)
            ->where('attempt_ref', $this->store->attemptForOperation($action->scope, $op, $ctx)?->attemptRef)->exists()) {
            $this->refuse();
        }
        $remaining = $capacity->confirmedRefund->minor;
        $links = [$input->resultRef, $fact->factRef, $released->releaseEvidenceRef];
        $seen = [$op => true];
        foreach ($replacementFacts as $replacement) {
            $sibling = $this->store->reservationForOperation($ctx, $replacement->operationRef);
            $amount = $replacement->snapshot->operationAmount?->minor;
            if (isset($seen[$replacement->operationRef]) || $replacement->snapshot->kind !== EffectKind::Refund || $replacement->snapshot->normalizedStatus !== 'succeeded'
                || $replacement->snapshot->currency !== $late->currency || $sibling?->state !== ReservationState::Consumed || $sibling->rootOperationRef !== $root
                || ! in_array($sibling->releaseEvidenceRef, $replacement->evidenceRefs, true) || $amount === null || $amount <= 0
                || $amount !== $sibling->consumed->minor || $amount > $remaining) {
                $this->refuse();
            }
            $seen[$replacement->operationRef] = true;
            $remaining -= $amount;
            $links[] = $replacement->factRef;
        }
        if ($replacementFacts === [] || $remaining !== 0) {
            $this->refuse();
        }

        return $links;
    }

    private function refuse(): never
    {
        throw new ProblemException(ProblemCode::RecoveryRequired, 'Late refund review requires the current held comparison, original release and all applied replacement effects');
    }
}
