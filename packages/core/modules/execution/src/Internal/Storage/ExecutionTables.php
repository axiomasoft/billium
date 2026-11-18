<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Storage;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\OperationRecord;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use LogicException;
use Packman\Attributes\Internal;
use WeakMap;

/**
 * Scope-checked access to the execution tables plus the per-transaction row-lock ledger.
 * Every execution storage component goes through this gate so the authorization and
 * lock rules cannot drift between them.
 */
#[Internal]
final class ExecutionTables
{
    /** @var WeakMap<TransactionContext,array<string,bool>> */
    private WeakMap $held;

    public function __construct(private readonly DatabaseManager $databases, private readonly OperationRunner $runner, private readonly CallerContext $callers, private readonly BilliumOptions $options)
    {
        $this->held = new WeakMap;
    }

    public function database(?TransactionContext $context = null): Connection
    {
        if ($context !== null) {
            $this->runner->assertCurrent($context, 'execution');
        }

        return $this->databases->connection($context?->connectionName() ?? $this->options->connection);
    }

    public function caller(?TransactionContext $context = null): Caller
    {
        return $context?->caller() ?? $this->callers->current();
    }

    public function financial(TransactionContext $context): void
    {
        $this->database($context);
        if (! $context->spec()->mode->financial() || $context->sessionEpoch() < 1) {
            throw new LogicException('Execution mutation requires current financial session');
        }
    }

    public function custody(TransactionContext $context): void
    {
        $this->database($context);
        if (! $context->spec()->mode->financial() && $context->spec()->mode !== FinancialMode::RawCustody) {
            throw new LogicException('Custody requires declared raw or financial root');
        }
    }

    public function selectedScope(TransactionContext $context): FinancialScope
    {
        $scope = $context->spec()->financialScope;
        $caller = $context->caller();
        if ($scope === null || $scope->installationRef !== $caller->installationRef || $scope->sellerRef !== $caller->sellerRef
            || $scope->environment !== $caller->environment || ! in_array($scope->intentOwnerNamespace, $caller->intentOwnerNamespaces, true)
            || ! in_array($scope->sourceAuthority, ['remote-billium', 'standalone-billium'], true)
            || ! in_array($scope->sourceAuthority, $caller->sourceAuthorities, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Execution requires an authenticated selected financial scope');
        }

        return $scope;
    }

    public function assertScope(ExecutionScope $scope, ?TransactionContext $context = null, ?string $profile = null): void
    {
        if ($context !== null) {
            $this->runner->assertCurrent($context, 'execution');
        }
        $caller = $this->caller($context);
        if ([$scope->installationRef, $scope->sellerRef, $scope->environment] !== [$caller->installationRef, $caller->sellerRef, $caller->environment] || ! in_array($scope->intentOwnerNamespace, $caller->intentOwnerNamespaces, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Execution scope is not authenticated');
        }
        $declared = $context?->spec()->financialScope;
        if ($declared !== null && ($declared->installationRef !== $scope->installationRef || $declared->sellerRef !== $scope->sellerRef
            || $declared->environment !== $scope->environment || $declared->intentOwnerNamespace !== $scope->intentOwnerNamespace)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Execution differs from the declared root scope');
        }
        if ($context !== null && $context->spec()->mode->financial()) {
            $selected = $this->selectedScope($context);
            if ($selected->intentOwnerNamespace !== $scope->intentOwnerNamespace || ($profile !== null && $selected->sourceAuthority !== $profile)) {
                throw new ProblemException(ProblemCode::AuthDenied, 'Execution differs from selected financial scope');
            }
        }
    }

    /** @return array{installation_ref:string,seller_ref:string,environment:string} */
    public static function scope(ExecutionScope $scope): array
    {
        return ['installation_ref' => $scope->installationRef, 'seller_ref' => $scope->sellerRef, 'environment' => $scope->environment];
    }

    /** @return array{installation_ref:string,seller_ref:string,environment:string} */
    public function callerScope(TransactionContext $context): array
    {
        $c = $context->caller();

        return ['installation_ref' => $c->installationRef, 'seller_ref' => $c->sellerRef, 'environment' => $c->environment];
    }

    public function query(string $table, ExecutionScope $scope, ?TransactionContext $context = null): Builder
    {
        $this->assertScope($scope, $context);

        $query = $this->database($context)->table('billium_execution_'.$table)->where(self::scope($scope));
        if ($table === 'operation') {
            $query->where('intent_owner_namespace', $scope->intentOwnerNamespace);
        } elseif ($table === 'attempt') {
            $query->whereIn('operation_ref', $this->query('operation', $scope, $context)->select('operation_ref'));
        }

        return $query;
    }

    public function callerQuery(string $table, TransactionContext $context): Builder
    {
        return $this->database($context)->table('billium_execution_'.$table)->where($this->callerScope($context));
    }

    /** @param list<string> $keys root operation refs or "table:id" row keys proven locked in this transaction */
    public function hold(TransactionContext $context, array $keys): void
    {
        $held = $this->held[$context] ?? [];
        foreach ($keys as $key) {
            $held[$key] = true;
        }
        $this->held[$context] = $held;
    }

    public function holds(TransactionContext $context, string $key): bool
    {
        return $this->held[$context][$key] ?? false;
    }

    public function requireLock(TransactionContext $context, string $rootRef): void
    {
        if (! $this->holds($context, $rootRef)) {
            throw new LogicException('Execution root/capacity lock required');
        }
    }

    public function requireOperationLock(TransactionContext $context, string $operationRef): void
    {
        $this->requireLock($context, $this->admittedOperation($context, $operationRef)->rootOperationRef);
    }

    public function operation(ExecutionScope $scope, string $operationRef, ?TransactionContext $context = null): ?OperationRecord
    {
        $row = $this->query('operation', $scope, $context)->where('operation_ref', $operationRef)->first();

        return $row === null ? null : ExecutionRecords::operation($row);
    }

    public function admittedOperation(TransactionContext $context, string $operationRef): OperationRecord
    {
        $row = $this->callerQuery('operation', $context)->where('operation_ref', $operationRef)->first() ?? throw new LogicException('Operation absent');
        $operation = ExecutionRecords::operation($row);
        $this->assertScope($operation->binding->scope, $context, $operation->binding->executionProfile);

        return $operation;
    }

    public static function refuse(string $reason): never
    {
        throw new ProblemException(ProblemCode::ValidationInvalid, $reason);
    }
}
