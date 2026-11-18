<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Storage;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ReconciliationRun;
use Billium\Execution\Data\RecoveryAction;
use LogicException;
use Packman\Attributes\Internal;

/** Reconciliation runs and repair receipts recorded against an original root. */
#[Internal]
final readonly class RecoveryRecordStorage
{
    public function __construct(private ExecutionTables $tables) {}

    public function appendReconciliationRun(TransactionContext $transactionContext, ReconciliationRun $reconciliationRun): void
    {
        $this->tables->financial($transactionContext);
        $this->tables->assertScope($reconciliationRun->scope, $transactionContext);
        $this->tables->database($transactionContext)->table('billium_execution_reconciliation_run')->insert(ExecutionTables::scope($reconciliationRun->scope) + $this->runRow($reconciliationRun));
    }

    public function advanceReconciliationRun(TransactionContext $transactionContext, ReconciliationRun $reconciliationRun, string $expectedState): void
    {
        $this->tables->financial($transactionContext);
        $this->tables->assertScope($reconciliationRun->scope, $transactionContext);
        $row = $this->runRow($reconciliationRun);
        unset($row['run_ref'],$row['merchant_account_ref'],$row['recovery_horizon'],$row['started_at']);
        if ($this->tables->query('reconciliation_run', $reconciliationRun->scope, $transactionContext)->where('run_ref', $reconciliationRun->runRef)->where('state', $expectedState)->update($row) !== 1) {
            ExecutionTables::refuse('Reconciliation CAS conflict');
        }
    }

    /** @param list<string> $correctionRefs */
    public function appendRepairReceipt(TransactionContext $transactionContext, RecoveryAction $recoveryAction, string $beforeDigest, string $afterDigest, array $correctionRefs): ExecutionReceipt
    {
        $this->tables->financial($transactionContext);
        $this->tables->assertScope($recoveryAction->scope, $transactionContext);
        $root = $this->tables->operation($recoveryAction->scope, $recoveryAction->rootOperationRef, $transactionContext) ?? throw new LogicException('Repair root unavailable');
        if ($root->operationRef !== $root->rootOperationRef) {
            ExecutionTables::refuse('Repair must name original root');
        }
        $this->tables->requireLock($transactionContext, $recoveryAction->rootOperationRef);
        if ($recoveryAction->evidenceRefs === []) {
            ExecutionTables::refuse('Recovery requires evidence');
        }
        $this->tables->database($transactionContext)->table('billium_execution_repair_receipt')->insert(ExecutionTables::scope($recoveryAction->scope) + ['repair_ref' => $recoveryAction->actionRef, 'root_operation_ref' => $recoveryAction->rootOperationRef, 'authorization_ref' => $recoveryAction->authorizationRef, 'reason' => $recoveryAction->reason, 'evidence_refs' => ExecutionCodec::encode($recoveryAction->evidenceRefs), 'before_digest' => $beforeDigest, 'after_digest' => $afterDigest, 'correction_refs' => ExecutionCodec::encode($correctionRefs), 'action_kind' => $recoveryAction->actionKind, 'intent_owner_namespace' => $recoveryAction->scope->intentOwnerNamespace]);

        return new ExecutionReceipt($recoveryAction->actionRef, $recoveryAction->actionRef, $recoveryAction->rootOperationRef, $recoveryAction->semanticDigest, 'recorded', false, $recoveryAction->evidenceRefs);
    }

    public function recordRecoveryAction(TransactionContext $transactionContext, RecoveryAction $recoveryAction): ExecutionReceipt
    {
        return $this->appendRepairReceipt($transactionContext, $recoveryAction, $recoveryAction->semanticDigest, $recoveryAction->semanticDigest, []);
    }

    /** @return array<string,mixed> */
    private function runRow(ReconciliationRun $r): array
    {
        return ['run_ref' => $r->runRef, 'merchant_account_ref' => $r->merchantAccountRef, 'recovery_horizon' => $r->recoveryHorizon->format(ExecutionRows::TIMESTAMP), 'inventory_cursor' => $r->inventoryCursor, 'completeness_watermark' => $r->completenessWatermark, 'inventory_digest' => $r->inventoryDigest, 'manifest_digest' => $r->manifestDigest, 'state' => $r->state, 'unresolved' => ExecutionCodec::encode($r->unresolved), 'started_at' => $r->startedAt->format(ExecutionRows::TIMESTAMP), 'completed_at' => $r->completedAt?->format(ExecutionRows::TIMESTAMP)];
    }
}
