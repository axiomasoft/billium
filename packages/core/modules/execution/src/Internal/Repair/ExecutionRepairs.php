<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Repair;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\RepairCommands;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\RecoveryAction;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Reconciliation\ReconciliationJournal;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Billium\Execution\Internal\Storage\ExecutionRows;
use Billium\Platform\Contracts\Outbox\Outbox;
use Billium\Platform\Data\EventContracts\EventContractRegistration;
use Billium\Platform\Data\EventContracts\RegisteredPublicEvent;
use Billium\Platform\Data\Outbox\OutboxEvent;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class ExecutionRepairs implements RepairCommands
{
    public function __construct(private ExecutionStore $store, private CallerContext $callers, private ReconciliationJournal $journal, private Outbox $outbox, private ModuleRegistry $modules) {}

    public function repair(RecoveryAction $action, string $reviewRef): StoredOutcome
    {
        $caller = $this->callers->current();
        if ($caller->actorRef !== $action->authorizationRef || ! in_array('execution.repair', $caller->permissions, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Repair requires the current authenticated operator grant');
        }
        $scope = $action->scope;
        $root = $this->store->operation($scope, $action->rootOperationRef) ?? throw new ProblemException(ProblemCode::ReferenceUnknown, 'Original repair root is unknown');
        $source = $root->binding->executionProfile;
        PaymentIdentity::select($caller, $scope, $source);
        if ($root->operationRef !== $root->rootOperationRef || $action->evidenceRefs === [] || count($action->evidenceRefs) > 16
            || trim($action->reason) === '' || strlen($action->reason) > 512 || count($action->evidenceRefs) !== count(array_unique($action->evidenceRefs))) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Repair requires a bounded reason and distinct original evidence');
        }
        $semantic = PaymentIdentity::digest(['schema' => 'billium.execution.repair.v1', 'scope' => get_object_vars($scope), 'source' => $source,
            'root' => $root->operationRef, 'review' => $reviewRef, 'action' => $action->actionKind, 'actor' => $caller->actorRef,
            'reason' => $action->reason, 'evidence' => $action->evidenceRefs]);
        if (! hash_equals($semantic, $action->semanticDigest)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Incorrect canonical repair digest');
        }
        $key = PaymentIdentity::commandKey($scope, 'execution.repair', $source, $action->actionRef);
        $ref = 'repair:'.substr($key, 7);
        $eventId = (string) Str::ulid();

        return $this->journal->write($scope, $source, 'execution.repair', function (TransactionContext $ctx) use ($action, $root, $source, $scope, $semantic, $reviewRef, $ref, $eventId): ExecutionReceipt {
            if ($ctx->caller()->actorRef !== $action->authorizationRef || ! in_array('execution.repair', $ctx->caller()->permissions, true)) {
                throw new ProblemException(ProblemCode::AuthDenied, 'Repair operator changed before the admitted transaction');
            }
            $this->journal->lockNamespace($ctx, $scope);
            $namespace = $this->store->namespace($scope, $ctx);
            $this->journal->lockRoot($ctx, $scope, $root->operationRef);
            $db = $this->journal->db($ctx);
            $route = ['installation_ref' => $scope->installationRef, 'seller_ref' => $scope->sellerRef, 'environment' => $scope->environment];
            $reviews = $db->table('billium_execution_recon_review')->where($route)->where(['source_authority' => $source, 'owner_namespace' => $scope->intentOwnerNamespace,
                'merchant_account_ref' => $root->binding->merchantAccountRef, 'revision_ref' => $root->binding->merchantRevisionRef,
                'currency' => $root->requested->currency->code, 'root_ref' => $root->operationRef]);
            $prior = (clone $reviews)->where('event_ref', $reviewRef)->first();
            if ($prior === null || $prior->status !== 'open' || $prior->evidence_ref !== $action->evidenceRefs[0]
                || (clone $reviews)->where('case_ref', $prior->case_ref)->where('id', '>', $prior->id)->exists()) {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Repair lacks the latest original review and matching evidence');
            }
            $before = ExecutionCodec::digest([$prior->event_ref, $prior->basis_digest, $prior->status]);
            $links = $this->verify($ctx, $db, $route, $action, $prior);
            if ($action->actionKind === 'close_namespace') {
                $this->store->transitionNamespace($ctx, $scope, NamespaceState::Closed, $namespace->generation, $ref);
            }
            $status = in_array($action->actionKind, ['attach_trusted_evidence', 'close_namespace', 'approve_linked_correction'], true) ? 'open' : 'resolved';
            $after = ExecutionCodec::digest([$before, $action->actionKind, $links, $status, $semantic]);
            $now = new DateTimeImmutable;
            $eventRef = 'repair-review:'.substr($semantic, 7);
            $record = get_object_vars($prior);
            unset($record['id']);
            $db->table('billium_execution_recon_review')->insert(array_replace($record, ['event_ref' => $eventRef, 'provenance' => $action->actionKind,
                'status' => $status, 'receipt_ref' => $ref, 'created_at' => ExecutionRows::stamp($now),
                'next_review_at' => $status === 'resolved' ? null : ExecutionRows::stamp($now)]));
            $storedAction = new RecoveryAction($ref, $scope, $action->actionKind, $action->evidenceRefs, $ctx->caller()->actorRef, $action->reason, $semantic, $root->operationRef);
            $receipt = $this->store->appendRepairReceipt($ctx, $storedAction, $before, $after, [$reviewRef, $eventRef, $eventId, ...$links]);
            $this->recordEvent($ctx, new OutboxEvent($eventId, 'execution.repair_recorded.v1', 'billium.event.v3', $ref, 1, $now,
                ['repairRef' => $ref, 'rootOperationRef' => $root->operationRef, 'originalReviewRef' => $reviewRef, 'reviewEventRef' => $eventRef,
                    'action' => $action->actionKind, 'beforeDigest' => $before, 'afterDigest' => $after]));

            return $receipt;
        }, $key, $semantic, $this->store->plan($scope, $root->operationRef, true)->participatingModuleIds);
    }

    private function recordEvent(TransactionContext $ctx, OutboxEvent $event): void
    {
        foreach ($this->modules->descriptor('execution')->publicEvents ?? [] as $declaration) {
            if ($declaration->eventType === $event->type && $declaration->envelopeSchema === $event->schemaVersion
                && $declaration->eventContractRevision === 'events-2026-11-v3') {
                $this->outbox->record($ctx, new RegisteredPublicEvent(EventContractRegistration::from('execution', $declaration), $event));

                return;
            }
        }
        throw new LogicException('Execution repair event contract is not registered');
    }

    /**
     * @param  array<string,string>  $route
     * @return list<string>
     */
    private function verify(TransactionContext $ctx, Connection $db, array $route, RecoveryAction $action, stdClass $review): array
    {
        $scope = $action->scope;
        $root = $action->rootOperationRef;
        $supplement = array_slice($action->evidenceRefs, 1);
        $links = [];
        $facts = [];
        $absenceAttempt = null;
        foreach ($supplement as $evidence) {
            $work = $db->table('billium_execution_provider_work')->where($route)->where('evidence_ref', $evidence)->where('state', 'processed')->first();
            $input = $work === null ? null : $this->store->providerWork(ExecutionRows::text($work->work_ref), $ctx);
            $op = $input?->operationHint === null ? null : $this->store->operation($scope, $input->operationHint, $ctx);
            if ($op === null || $op->rootOperationRef !== $root || $op->binding->merchantAccountRef !== $review->merchant_account_ref
                || $op->binding->merchantRevisionRef !== $review->revision_ref || $op->binding->executionProfile !== $review->source_authority
                || $op->requested->currency->code !== $review->currency || get_object_vars($input->evidence->scope) !== get_object_vars($scope)) {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Supplementary evidence is not an accepted result of the original root');
            }
            $fact = $this->store->factForEvidence($ctx, $evidence, $op->operationRef);
            if ($fact !== null) {
                $facts[] = $fact;
                $links[] = $fact->factRef;
            } elseif ($action->actionKind === 'record_verified_absence' && $db->table('billium_execution_certified_absence')->where($route)
                ->where('evidence_ref', $evidence)->where('attempt_ref', $this->store->attemptForOperation($scope, $op->operationRef, $ctx)?->attemptRef)->exists()) {
                $absenceAttempt = $this->store->attemptForOperation($scope, $op->operationRef, $ctx)?->attemptRef;
                $links[] = $evidence;
            } else {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Evidence has no authoritative applied fact or fenced absence');
            }
        }
        if ($action->actionKind === 'resolve_discrepancy' && $review->code === 'material_conflict') {
            return new LateRefundAdjudication($this->store)->links($ctx, $db, $route, $action, $review, $facts);
        }
        if ($action->actionKind === 'approve_linked_correction') {
            return new LinkedCorrectionApproval($this->store)->links($ctx, $db, $route, $action, $review, $facts);
        }
        $observed = ExecutionCodec::map(ExecutionRows::text($review->observed));
        $valid = match ($action->actionKind) {
            'attach_trusted_evidence' => $facts !== [],
            'adopt_external_effect' => $review->code === 'external_manual' && $review->provenance === 'original_provider_inventory' && $supplement === [],
            'mark_verified_duplicate' => count($facts) === 1 && ($observed['factRef'] ?? null) === $facts[0]->snapshot->stableEffectId
                && ($observed['minor'] ?? null) === $facts[0]->snapshot->operationAmount?->minor && ($observed['kind'] ?? null) === $facts[0]->snapshot->kind->value,
            'record_verified_absence' => $review->code === 'manual_required' && count($links) === 1 && $facts === []
                && $absenceAttempt !== null && ($observed['attemptRef'] ?? null) === $absenceAttempt,
            'close_namespace' => true,
            'resolve_discrepancy' => $review->code === 'balance_mismatch' && ($observed['minor'] ?? null) === $this->store->capacity($ctx, $root)->confirmedRefund->minor,
            default => false,
        };
        if (! $valid) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Requested repair has no supporting original evidence and comparison');
        }

        return $links;
    }
}
