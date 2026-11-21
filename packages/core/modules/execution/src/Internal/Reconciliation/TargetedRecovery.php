<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Reconciliation;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Internal\Dispatch\TransportBoundary;
use Billium\Execution\Internal\Disputes\InventoryComparison;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Billium\Execution\Internal\Storage\ExecutionRows;
use DateTimeImmutable;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class TargetedRecovery
{
    public function __construct(private ExecutionStore $store, private AttemptWorker $worker, private EvidenceReducer $reducer,
        private ReconciliationJournal $journal, private TransportBoundary $boundary, private InventoryComparison $comparisons) {}

    public function recover(ExecutionScope $scope, string $ref, string $attemptRef, string $worker, DateTimeImmutable $leaseUntil): StoredOutcome
    {
        $this->boundary->assertOutsideTransaction();
        $attempt = $this->store->attempt($scope, $attemptRef) ?? throw new ProblemException(ProblemCode::ReferenceUnknown, 'Unknown scoped recovery attempt');
        $op = $this->store->operation($scope, $attempt->operationRef) ?? throw new ProblemException(ProblemCode::ReferenceUnknown, 'Unknown original operation');
        $merchant = $this->store->merchantRevision($scope, $op->binding->merchantRevisionRef);
        $w = new RecoveryWindow($scope, $op->binding->executionProfile, $ref, $merchant, $op->requested->currency->code,
            $attempt->startedAt ?? $op->createdAt, $leaseUntil, $attemptRef);
        $start = $this->journal->write($scope, $w->source, 'execution.begin_targeted_lookup', fn (TransactionContext $ctx): ExecutionReceipt => $w->receipt(ExecutionRows::text($this->journal->current($ctx, $w)->state)));
        if ($start->body['status'] !== 'running') {
            return $start;
        }
        $refs = [];
        $works = [];
        $reason = $attempt->state->value;
        if (in_array($attempt->state, [AttemptState::Dispatching, AttemptState::Pending, AttemptState::Unknown], true)) {
            // Attempt states never reverse to admitted. Recovery of this set can only look up the original reference.
            $receipt = $this->worker->recover($scope, $attemptRef, $worker, $leaseUntil)->orThrow();
            $transport = ExecutionRows::text($receipt->body['receiptRef'] ?? null);
            $refs[] = $transport;
            $this->journal->write($scope, $w->source, 'execution.read_lookup_result', function (TransactionContext $ctx) use ($w, $transport, $op, &$refs, &$works, &$reason): ExecutionReceipt {
                $this->journal->current($ctx, $w);
                $db = $this->journal->db($ctx);
                $row = $db->table('billium_execution_provider_evidence')->where($w->scopeFields())->where('intent_owner_namespace', $w->scope->intentOwnerNamespace)
                    ->where('merchant_account_ref', $w->merchant->merchantAccountRef)->where('provider_id', $w->merchant->providerId)->where('evidence_ref', $transport)->first();
                if ($row === null) {
                    throw new ProblemException(ProblemCode::RecoveryRequired, 'Missing immutable lookup custody');
                }
                $data = ExecutionCodec::map(ExecutionRows::text($row->sanitized_payload));
                if (($data['attemptRef'] ?? null) !== $w->attempt || ($data['operationRef'] ?? null) !== $op->operationRef
                    || ! in_array($data['source'] ?? null, ['lookup', 'verified_absence'], true)) {
                    throw new ProblemException(ProblemCode::RecoveryRequired, 'Unexpected lookup provenance');
                }
                $reason = ExecutionRows::text($data['lookupState'] ?? null);
                $evidenceRef = $data['retainedProviderEvidenceRef'] ?? null;
                if (is_string($evidenceRef)) {
                    $refs[] = $evidenceRef;
                }
                $works = $db->table('billium_execution_provider_work')->where($w->scopeFields())->whereIn('evidence_ref', $refs)
                    ->where('operation_hint', $op->operationRef)->orderBy('id')->pluck('work_ref')->map(ExecutionRows::text(...))->all();

                return $w->receipt('retained', $refs);
            });
            foreach ($works as $work) {
                $this->reducer->reduce($work);
            }
        }

        return $this->journal->write($scope, $w->source, 'execution.record_targeted_lookup', function (TransactionContext $ctx) use ($op, $w, $refs, $works, $reason): ExecutionReceipt {
            $row = $this->journal->current($ctx, $w);
            if ($row->state !== 'running') {
                return $w->receipt(ExecutionRows::text($row->state));
            }
            $db = $this->journal->db($ctx);
            $basis = $this->comparisons->basis($ctx, $w, $op->rootOperationRef);
            $now = $this->store->attempt($w->scope, (string) $w->attempt, $ctx) ?? throw new LogicException('Original attempt disappeared');
            if ($refs === []) {
                $retained = $db->table('billium_execution_attempt')->where($w->scopeFields())->where('attempt_ref', $now->attemptRef)->value('finish_evidence_ref');
                if (is_string($retained)) {
                    $refs[] = $retained;
                }
            }
            $digest = ExecutionCodec::digest([$w->attempt, $reason, $refs, $works, $basis]);
            $state = match (true) {
                ($basis['financialHold'] ?? null) === true => 'parked',
                $refs !== [] && in_array($now->state, [AttemptState::Succeeded, AttemptState::Failed, AttemptState::Cancelled], true) => 'complete',
                $reason === 'unsupported', $now->state === AttemptState::Admitted => 'parked',
                default => 'incomplete',
            };
            $observed = ['attemptRef' => $now->attemptRef, 'state' => $now->state->value, 'lookupState' => $reason, 'evidenceRefs' => $refs];
            $ref = $refs[0] ?? $now->attemptRef;
            $review = $this->comparisons->review($ctx, $w, $now->attemptRef, 'manual_required', $op->rootOperationRef, $ref, $digest, $basis, $observed, $state === 'complete');
            $db->table('billium_execution_recon_page')->insertOrIgnore(['run_id' => $row->id, 'page_no' => 1, 'cursor_in' => null, 'cursor_out' => null,
                'report_ref' => $now->attemptRef, 'credential_version_ref' => $w->merchant->credentialVersionRef, 'source_digest' => $digest, 'items_digest' => $digest, 'complete_through' => null, 'item_count' => 1]);
            $db->table('billium_execution_recon_item')->insertOrIgnore(['run_id' => $row->id, 'page_no' => 1, 'item_no' => 0, 'fact_ref' => $now->attemptRef,
                'provider_revision' => null, 'evidence_ref' => $ref, 'work_ref' => $works[0] ?? null, 'kind' => $op->kind->value,
                'root_ref' => $op->rootOperationRef, 'operation_ref' => $op->operationRef, 'source_digest' => $digest, 'comparison_basis' => ExecutionCodec::encode($basis),
                'observed' => ExecutionCodec::encode($observed), 'disposition' => $now->state->value, 'review_ref' => $review]);

            return $this->journal->finishIn($ctx, $w, $row, null, $state, null, $state === 'complete' ? 'targeted:'.$digest : null, $digest);
        });
    }
}
