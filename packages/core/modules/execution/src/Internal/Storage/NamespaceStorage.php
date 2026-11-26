<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Storage;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\NamespaceRecord;
use Billium\Execution\Enums\NamespaceState;
use Packman\Attributes\Internal;

/** Business intent namespaces: their durable history state, generation-checked transitions and the shared admission lock. */
#[Internal]
final readonly class NamespaceStorage
{
    public function __construct(private ExecutionTables $tables) {}

    public function namespace(ExecutionScope $executionScope, ?TransactionContext $context = null): NamespaceRecord
    {
        $row = $this->tables->query('namespace', $executionScope, $context)->where('intent_owner_namespace', $executionScope->intentOwnerNamespace)->first();

        return ExecutionRecords::namespace($executionScope, $row);
    }

    public function lockNamespace(TransactionContext $context, ExecutionScope $scope): NamespaceRecord
    {
        $this->tables->financial($context);
        $id = $this->tables->query('namespace', $scope, $context)->where('intent_owner_namespace', $scope->intentOwnerNamespace)->value('id');
        if ($id === null) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Business namespace lacks durable history');
        }
        $request = new LockRequest(LockRank::IntentNamespace, 'billium_execution_namespace', [ExecutionRows::integer($id)], shared: true);
        if ($context->lock($request) !== $request->keys) {
            throw new LockPlanChanged('Business namespace changed during admission');
        }

        return $this->namespace($scope, $context);
    }

    public function transitionNamespace(TransactionContext $transactionContext, ExecutionScope $executionScope, NamespaceState $namespaceState, int $expectedGeneration, string $authorityEvidenceRef): NamespaceRecord
    {
        $this->tables->financial($transactionContext);
        $this->tables->assertScope($executionScope, $transactionContext);
        if ($authorityEvidenceRef === '') {
            ExecutionTables::refuse('Namespace transition lacks authority evidence');
        }
        $q = $this->tables->query('namespace', $executionScope, $transactionContext)->where('intent_owner_namespace', $executionScope->intentOwnerNamespace);
        if ($expectedGeneration === 0) {
            $inserted = $this->tables->database($transactionContext)->table('billium_execution_namespace')->insertOrIgnore(ExecutionTables::scope($executionScope) + ['intent_owner_namespace' => $executionScope->intentOwnerNamespace, 'state' => $namespaceState->value, 'canonicalization_version' => 'execution.intent.v1', 'completeness_receipt_ref' => $authorityEvidenceRef, 'generation' => 1]);
            if ($inserted !== 1) {
                ExecutionTables::refuse('Namespace creation race');
            }
        } else {
            $id = (clone $q)->value('id');
            if ($id === null || $transactionContext->lock(new LockRequest(LockRank::IntentNamespace, 'billium_execution_namespace', [ExecutionRows::integer($id)])) !== [ExecutionRows::integer($id)]
                || $q->where('generation', $expectedGeneration)->update(['state' => $namespaceState->value, 'completeness_receipt_ref' => $authorityEvidenceRef, 'generation' => $expectedGeneration + 1]) !== 1) {
                ExecutionTables::refuse('Namespace generation conflict');
            }
        }

        return $this->namespace($executionScope, $transactionContext);
    }
}
