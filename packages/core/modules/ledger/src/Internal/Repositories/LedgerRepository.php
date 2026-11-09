<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Repositories;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\LockRequest;
use Billium\Core\Enums\FinancialMode;
use Billium\Core\Enums\LockRank;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Exceptions\LockPlanChanged;
use Billium\Ledger\Data\AdjustObligation;
use Billium\Ledger\Data\AmendObligationDuePolicy;
use Billium\Ledger\Data\DeclareSettlementIntent;
use Billium\Ledger\Data\LedgerReceipt;
use Billium\Ledger\Data\RecordCustomerCredit;
use Billium\Ledger\Data\RecordObligation;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use LogicException;

/** Query builder reads and typed DB calls; no PHP counter writes (D60). */
final readonly class LedgerRepository
{
    public function __construct(private DatabaseManager $databases, private OperationRunner $runner) {}

    /**
     * @param  list<ModuleOperationClass>  $allowedClasses
     * @param  list<FinancialMode>  $allowedModes
     */
    public function assertMonetary(
        TransactionContext $context,
        array $allowedClasses = [
            ModuleOperationClass::NewIntent,
            ModuleOperationClass::FrozenIntentCompletion,
            ModuleOperationClass::OriginalOwnerRefund,
        ],
        array $allowedModes = [FinancialMode::Active],
    ): void {
        $this->runner->assertCurrent($context, 'ledger');
        // @phpstan-ignore identical.alwaysFalse (Keep the explicit admission invariant although sessionEpoch returns int or throws.)
        if ($allowedModes === [] || ! in_array($context->spec()->mode, $allowedModes, true) || $context->sessionEpoch() === null || ! $context->spec()->monetary()) {
            throw new LogicException('Monetary ledger operations require an admitted financial session and commit accounting (D54, D60, D62)');
        }
        if (! in_array($context->spec()->operationClass, $allowedClasses, true)) {
            throw new LogicException('Monetary ledger operation is not permitted for operation class '.$context->spec()->operationClass->value.' (D55, D60)');
        }
    }

    /**
     * @param  list<ModuleOperationClass>  $allowedClasses
     * @param  list<FinancialMode>  $allowedModes
     */
    public function assertFinancialSession(
        TransactionContext $context,
        array $allowedClasses = [
            ModuleOperationClass::ObservedFactRecording,
            ModuleOperationClass::UnknownResolution,
        ],
        array $allowedModes = [FinancialMode::Active],
    ): void {
        $this->runner->assertCurrent($context, 'ledger');
        // @phpstan-ignore identical.alwaysFalse (Keep the explicit admission invariant although sessionEpoch returns int or throws.)
        if ($allowedModes === [] || ! in_array($context->spec()->mode, $allowedModes, true) || $context->sessionEpoch() === null) {
            throw new LogicException('Ledger operations require an admitted financial session (D54, D60)');
        }
        if (! in_array($context->spec()->operationClass, $allowedClasses, true)) {
            throw new LogicException('Ledger operation is not permitted for operation class '.$context->spec()->operationClass->value.' (D55, D60)');
        }
    }

    public function execute(TransactionContext $context, RecordObligation|AdjustObligation|AmendObligationDuePolicy|RecordCustomerCredit|DeclareSettlementIntent $command): LedgerReceipt
    {
        $this->assertMonetary($context);
        CommandPayload::validate($command);
        $caller = $context->caller();
        if ($caller->sellerRef !== $command->scope->sellerRef || $caller->environment !== $command->scope->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'participant scope differs from caller');
        }
        $connection = $this->databases->connection($context->connectionName());
        $prefix = [$caller->installationRef, $caller->sellerRef, $caller->environment, $command->scope->billingAccountRef];
        $digest = CommandPayload::digest($command);
        if ($command instanceof RecordObligation) {
            $p = $command->duePolicy;

            return $this->call($connection, 'record_obligation', [...$prefix, $command->money->currency->code,
                $command->obligationRef, $command->basisRef, $command->customerRef, $command->componentRef, $command->money->minor,
                $command->termsDigest, $command->priceVersionRef, $command->taxSnapshotRef, $p->dueAt?->format('Y-m-d\TH:i:s.uP'),
                $p->duePolicyVersion, $p->sourcePolicyRef, $p->sourcePolicySnapshotDigest, $p->collectionEligibleAt?->format('Y-m-d\TH:i:s.uP'), $digest], 'ledger.obligation');
        }
        if ($command instanceof AmendObligationDuePolicy) {
            $id = $connection->table('billium_obligation')->where('seller_ref', $caller->sellerRef)->where('environment', $caller->environment)
                ->where('billing_account_ref', $command->scope->billingAccountRef)->where('obligation_ref', $command->obligationRef)->value('id');
            if ($id === null) {
                throw new ProblemException(ProblemCode::ReferenceUnknown, 'unknown obligation');
            }
            $context->lock(new LockRequest(LockRank::Obligation, 'billium_obligation', [self::id($id)], guardedRoutine: 'billium_lock_obligation'));
            $p = $command->duePolicy;

            return $this->call($connection, 'amend_obligation_due_policy', [...$prefix, self::id($id),
                $p->dueAt?->format('Y-m-d\TH:i:s.uP'), $p->duePolicyVersion, $p->sourcePolicyRef, $p->sourcePolicySnapshotDigest,
                $p->collectionEligibleAt?->format('Y-m-d\TH:i:s.uP'), 'due-policy:'.hash('sha256', $command->commandKey), $digest], 'ledger.obligation_due_policy');
        }
        if ($command instanceof AdjustObligation) {
            $query = $connection->table('billium_obligation')->where('seller_ref', $caller->sellerRef)->where('environment', $caller->environment)->where('obligation_ref', $command->obligationRef);
            $before = $query->first(['id', 'billed_invoice_id']);
            if ($before === null) {
                throw new ProblemException(ProblemCode::ReferenceUnknown, 'unknown obligation');
            }
            $id = self::id($before->id);
            $requests = [new LockRequest(LockRank::Obligation, 'billium_obligation', [$id], guardedRoutine: 'billium_lock_obligation')];
            if ($before->billed_invoice_id !== null) {
                $requests[] = new LockRequest(LockRank::Invoice, 'billium_invoice', [self::id($before->billed_invoice_id)], guardedRoutine: 'billium_lock_invoice');
            }
            $context->lock(...$requests);
            if ($query->value('billed_invoice_id') !== $before->billed_invoice_id) {
                throw new LockPlanChanged('invoice attachment changed');
            }

            return $this->call($connection, 'adjust_obligation', [...$prefix, $command->money->currency->code, $command->adjustmentRef, $id, $command->money->minor, $command->causeRef, $digest], 'ledger.adjustment');
        }
        if ($command instanceof RecordCustomerCredit) {
            return $this->call($connection, 'record_customer_credit', [...$prefix, $command->money->currency->code, $command->creditRef, $command->sourceRef, $command->money->minor, $digest], 'ledger.customer_credit');
        }
        $intentQuery = $connection->table('billium_settlement_intent')->where('seller_ref', $caller->sellerRef)->where('environment', $caller->environment)->where('business_intent_ref', $command->businessIntentRef);
        $intentId = $intentQuery->value('id');
        $targets = $connection->table('billium_obligation')->where('seller_ref', $caller->sellerRef)->where('environment', $caller->environment)->whereIn('obligation_ref', $command->obligationRefs)->pluck('id', 'obligation_ref');
        $ids = [];
        foreach ($command->obligationRefs as $ref) {
            if (! isset($targets[$ref])) {
                throw new ProblemException(ProblemCode::ReferenceUnknown, 'unknown settlement target');
            }
            $ids[] = self::id($targets[$ref]);
        }
        $requests = [];
        if ($intentId !== null) {
            $requests[] = new LockRequest(LockRank::SettlementIntent, 'billium_settlement_intent', [self::id($intentId)], guardedRoutine: 'billium_lock_settlement_intent');
        }
        $requests[] = new LockRequest(LockRank::Obligation, 'billium_obligation', $ids, guardedRoutine: 'billium_lock_obligation');
        $context->lock(...$requests);
        if ($intentQuery->value('id') !== $intentId) {
            throw new LockPlanChanged('settlement identity changed');
        }

        return $this->call($connection, 'declare_settlement_intent', [...$prefix, $command->currency, $command->businessIntentRef, $command->policyVersion, '{'.implode(',', $ids).'}', $digest], 'ledger.settlement_intent');
    }

    private static function id(mixed $value): int
    {
        return is_int($value) ? $value : throw new LogicException('Expected a PostgreSQL bigint id');
    }

    /** @param list<mixed> $bindings */
    public function call(Connection $connection, string $routine, array $bindings, string $kind): LedgerReceipt
    {
        // A PL/pgSQL refusal aborts its statement. Restore a local savepoint before converting it to a D31 refusal;
        // the runner must still be able to validate the live root and save the business receipt.
        $connection->beginTransaction();
        try {
            $row = $connection->selectOne('SELECT public.billium_'.$routine.'('.implode(',', array_fill(0, count($bindings), '?')).') AS result', $bindings);
            $connection->commit();
        } catch (QueryException $exception) {
            $connection->rollBack();
            // Laravel's message includes SQL bindings; only PostgreSQL's primary error identifies a refusal.
            // The severity label is localized by lc_messages (ERROR, ОШИБКА, ...), so accept any label before the first colon.
            $message = $exception->errorInfo[2] ?? null;
            if (is_string($message) && preg_match('/\A[^:\r\n]+:[ \t]+([^\r\n]+)(?:\r?\n|\z)/u', $message, $matches) === 1) {
                $problem = ProblemCode::tryFrom(trim($matches[1]));
                if ($problem !== null) {
                    throw new ProblemException($problem, 'ledger routine refused '.$routine);
                }
            }
            $state = $exception->errorInfo[0] ?? null;
            $problem = match ($state) {
                '23502', '23514', '22003', '22001', '22P02' => ProblemCode::ValidationInvalid,
                '23503' => ProblemCode::ReferenceUnknown,
                '23505' => ProblemCode::IdempotencyConflict,
                default => null,
            };
            if ($problem !== null) {
                throw new ProblemException($problem, 'ledger input refused by relational constraints');
            }
            throw $exception;
        }
        $json = is_object($row) && is_string($row->result ?? null) ? $row->result : throw new LogicException('Missing ledger result');
        $result = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($result) || ! is_array($result['body'] ?? null)) {
            throw new LogicException('Invalid ledger receipt');
        }
        /** @var array<string,mixed> $body */
        $body = $result['body'];

        return new LedgerReceipt($kind, $body, ($result['replayed'] ?? false) === true);
    }
}
