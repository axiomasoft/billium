<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Reconciliation;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\InventoryReportFact;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Enums\DriverCapability;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Internal\Custody\VerifyDelivery;
use Billium\Execution\Internal\Disputes\InventoryComparison;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use DateTimeImmutable;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class InventoryItems
{
    public function __construct(private ReconciliationJournal $journal, private OperationRunner $runner, private ExecutionStore $store,
        private VerifyDelivery $verification, private EvidenceReducer $reducer, private InventoryComparison $comparisons) {}

    public function record(RecoveryWindow $w, int $runId, int $pageNo, int $index, ProviderEvidence|InventoryReportFact $item, CapabilitySnapshot $cap): void
    {
        $work = null;
        if ($item instanceof InventoryReportFact) {
            $kind = $item->kind;
            if ($kind === 'dispute' || $kind === 'chargeback') {
                $cap->requireCapability(DriverCapability::Disputes, $w->scope->environment === 'production');
            } elseif (in_array($kind, ['fee', 'settlement', 'balance_comparison'], true)
                && ! $cap->details->settlementListing && ! $cap->details->settlementExport) {
                throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Historical merchant has no qualified statement capability');
            }
            $fact = $item->factRef;
            $revision = $item->revision;
            $root = $item->rootOperationRef;
            $operation = null;
            $evidence = $fact;
            $source = $item->sourceDigest;
            $amount = $item->amount->minor;
            $currency = $item->amount->currency->code;
            $observedAt = $item->observedAt;
            $observed = ['factRef' => $fact, 'providerRevision' => $revision, 'kind' => $kind, 'currency' => $currency, 'minor' => $amount,
                'observedAt' => ReconciliationJournal::stamp($observedAt), 'sourceDigest' => $source];
            $code = $kind === 'balance_comparison' ? 'balance_mismatch' : $kind;
            $disposition = 'exposure';
            if ($item->finalAfter !== null && $w->through < $item->finalAfter) {
                $code = null;
                $disposition = 'provisional';
            }
        } else {
            $verified = $this->verification->verify($w->scope, $w->merchant->revisionRef, null, $item, false);
            $snapshot = $verified->snapshot;
            $currency = $snapshot->currency;
            $observedAt = $snapshot->observedAt;
            $this->checkWindow($w, $currency, $observedAt);
            $op = $verified->operation;
            if ($op !== null) {
                $this->comparisons->assertOriginal($w, $op);
            }
            $fact = $snapshot->stableEffectId ?? $snapshot->providerOperationId ?? $snapshot->operationRef;
            $kind = $snapshot->kind->value;
            $revision = $snapshot->providerRevision;
            $operation = $snapshot->operationRef;
            $root = $op?->rootOperationRef;
            $source = $item->payloadDigest;
            $amount = $snapshot->operationAmount?->minor;
            $observed = ['factRef' => $fact, 'providerRevision' => $revision, 'kind' => $kind, 'currency' => $currency, 'minor' => $amount,
                'observedAt' => ReconciliationJournal::stamp($observedAt), 'sourceDigest' => $source, 'normalizedStatus' => $snapshot->normalizedStatus, 'materialDigest' => $snapshot->materialDigest,
                'authorizedMinor' => $snapshot->authorizedTotal->minor, 'capturedMinor' => $snapshot->capturedTotal->minor,
                'refundedMinor' => $snapshot->refundedTotal->minor, 'voidedMinor' => $snapshot->voidedTotal->minor,
                'providerOperationId' => $snapshot->providerOperationId, 'originalBindingDigest' => $snapshot->bindingDigest,
                'originalProviderReference' => $snapshot->providerReference];
            $retain = function (TransactionContext $ctx) use ($w, $item, $operation, $root, $fact, $source, $observed): ExecutionReceipt {
                PaymentIdentity::select($ctx->caller(), $w->scope, $w->source);
                if (! in_array('execution.reconcile', $ctx->caller()->permissions, true)) {
                    throw new ProblemException(ProblemCode::AuthDenied, 'Inventory custody requires the current reconciliation grant');
                }
                if ($root === null) {
                    // Serialize the first durable observation of a lost send with admission of a new intent.
                    $this->journal->current($ctx, $w);
                    if ($this->store->operation($w->scope, $operation, $ctx) !== null) {
                        throw new ProblemException(ProblemCode::RecoveryRequired, 'Original operation appeared during inventory verification; reverify the signed item');
                    }
                }
                $ref = $this->store->appendEvidence($ctx, $item);
                $work = $this->store->enqueueProviderWork($ctx, $ref, 'inventory-work:'.substr(ExecutionCodec::digest([$w->scope, $ref, $item->handlerContractVersion]), 7), $item->handlerContractVersion, $operation);
                if ($root === null) {
                    $review = $this->comparisons->review($ctx, $w, $fact, 'missing_local_effect', null, $ref, $source, ['status' => 'no_local_binding'], $observed);
                    $namespace = $this->store->namespace($w->scope, $ctx);
                    if ($review !== null && $namespace->state === NamespaceState::Open) {
                        $this->store->transitionNamespace($ctx, $w->scope, NamespaceState::RecoveryRequired, $namespace->generation, $review);
                    }
                }

                return $w->receipt('retained', [$ref, $work]);
            };
            $custody = $root === null ? $this->journal->write($w->scope, $w->source, 'execution.hold_lost_inventory_effect', $retain)
                : $this->runner->run(new OperationSpec('execution.inventory_custody', $w->scope->sellerRef, $w->scope->environment, FinancialMode::RawCustody,
                    ModuleOperationClass::CallbackCustody, ['execution', 'platform'], financialScope: new FinancialScope($w->scope->installationRef, $w->scope->sellerRef,
                        $w->scope->environment, $w->scope->intentOwnerNamespace, $w->source)), $retain)->orThrow();
            $refs = $custody->body['evidenceRefs'] ?? null;
            if (! is_array($refs) || ! is_string($refs[0] ?? null) || ! is_string($refs[1] ?? null)) {
                throw new LogicException('Inventory custody lost its processing identity');
            }
            [$evidence, $work] = $refs;
            $result = $this->reducer->reduce($work);
            $retained = $this->store->providerWork($work);
            $priorConflict = $result->disposition === ReductionDisposition::Duplicate && $retained?->resultRef !== null && $retained->resultRef !== $evidence;
            $disposition = $priorConflict ? ReductionDisposition::HeldDiscrepancy->value : $result->disposition->value;
            $code = match (true) {
                $op === null => 'missing_local_effect',
                $priorConflict, $result->disposition === ReductionDisposition::HeldDiscrepancy, $result->disposition === ReductionDisposition::Quarantined => 'material_conflict',
                default => null,
            };
        }
        $this->checkWindow($w, $currency, $observedAt);
        $this->journal->write($w->scope, $w->source, 'execution.compare_inventory_item', function (TransactionContext $ctx) use ($w, $runId, $pageNo, $index,
            $fact, $revision, $root, $operation, $kind, $source, $evidence, $work, $code, $disposition, $observed, $amount): ExecutionReceipt {
            $run = $this->journal->current($ctx, $w);
            if (ReconciliationJournal::integer($run->id) !== $runId) {
                throw new LogicException('Run identity changed');
            }
            $table = $this->journal->db($ctx)->table('billium_execution_recon_item');
            $key = ['run_id' => $runId, 'page_no' => $pageNo, 'item_no' => $index];
            $old = (clone $table)->where($key)->first();
            if ($old !== null) {
                if ($old->source_digest !== $source || $old->fact_ref !== $fact || $old->provider_revision !== $revision) {
                    throw new ProblemException(ProblemCode::RecoveryRequired, 'Immutable inventory page item changed during retry');
                }

                return $w->receipt('retained', [$evidence]);
            }
            $basis = $this->comparisons->basis($ctx, $w, $root);
            $consistent = $code === 'balance_mismatch' && ($basis['refundedMinor'] ?? null) === $amount;
            $review = $code === null ? null : $this->comparisons->review($ctx, $w, $fact, $code, $root, $evidence, $source, $basis, $observed, $consistent);
            $table->insert($key + ['fact_ref' => $fact, 'provider_revision' => $revision, 'evidence_ref' => $evidence, 'work_ref' => $work,
                'kind' => $kind, 'root_ref' => $root, 'operation_ref' => $operation, 'source_digest' => $source, 'comparison_basis' => ExecutionCodec::encode($basis),
                'observed' => ExecutionCodec::encode($observed), 'disposition' => $consistent ? 'consistent' : $disposition, 'review_ref' => $review]);

            return $w->receipt('compared', [$evidence]);
        });
    }

    private function checkWindow(RecoveryWindow $window, string $currency, DateTimeImmutable $at): void
    {
        if ($currency !== $window->currency || $at < $window->from || $at > $window->through) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Inventory item has a foreign currency or falls outside its authenticated horizon');
        }
    }
}
