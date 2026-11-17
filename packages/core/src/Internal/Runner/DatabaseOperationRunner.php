<?php

declare(strict_types=1);

namespace Billium\Core\Internal\Runner;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Platform\Contracts\Idempotency\IdempotencyStore;
use Billium\Platform\Contracts\Installation\RootAdmission;
use Closure;
use Illuminate\Database\Connection;
use LogicException;
use Packman\Attributes\Internal;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * The root runner (operation-runner, M-55). Everything that changes money runs inside exactly one call of run() on a
 * connection at transaction level 0; there is no nested runner and no participant may start one.
 */
#[Internal]
final class DatabaseOperationRunner implements OperationRunner
{
    /** SQLSTATEs whose whole database work may be repeated: serialization failure and deadlock (COV-288). */
    private const array RETRYABLE = ['40001', '40P01'];

    /** The live attempt; the only context assertCurrent() accepts. */
    private ?Operation $current = null;

    /** @param Closure(): CallerContext $callers resolved per run, so a request-scoped host binding is honoured */
    public function __construct(
        private readonly Connection $connection,
        private readonly Closure $callers,
        private readonly IdempotencyStore $idempotency,
        private readonly RootAdmission $admission,
        private readonly int $maxAttempts = 3,
        private readonly int $deadlineMs = 30_000,
    ) {
        if ($maxAttempts < 1 || $deadlineMs < 1) {
            throw new LogicException('Runner budget and deadline are positive');
        }
    }

    public function run(OperationSpec $spec, Closure $body): StoredOutcome
    {
        if ($this->current !== null || $this->connection->transactionLevel() !== 0) {
            throw new LogicException("{$spec->commandKind}: OperationRunner is root-only; use a typed participant with the current TransactionContext (D60)");
        }
        // Step 1: authenticate before any write; payload refs are selectors, never authority (INV-14, D31: none).
        $caller = ($this->callers)()->current();
        if ($caller->sellerRef !== $spec->sellerRef || $caller->environment !== $spec->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'operation scope differs from the authenticated caller');
        }

        $scope = $spec->financialScope;
        $digest = $spec->digest;
        if ($scope !== null) {
            if ($scope->installationRef !== $caller->installationRef || $scope->sellerRef !== $caller->sellerRef
                || $scope->environment !== $caller->environment
                || ($scope->intentOwnerNamespace !== null && ! in_array($scope->intentOwnerNamespace, $caller->intentOwnerNamespaces, true))
                || ($scope->sourceAuthority !== null && ! in_array($scope->sourceAuthority, $caller->sourceAuthorities, true))) {
                throw new ProblemException(ProblemCode::AuthDenied, 'selected financial scope differs from the authenticated grants');
            }
            if ($digest !== null && ($scope->intentOwnerNamespace !== null || $scope->sourceAuthority !== null)) {
                $digest = 'sha256:'.hash('sha256', json_encode(['billium.financial_claim.v1', $scope->installationRef,
                    $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, $scope->sourceAuthority, $digest], JSON_THROW_ON_ERROR));
            }
        }

        for ($attempt = 1; ; $attempt++) {
            $operation = null;
            $claimId = null;
            $this->connection->beginTransaction();
            try {
                $started = hrtime(true);
                $this->connection->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $this->connection->statement('SET LOCAL statement_timeout = '.$this->deadlineMs);
                $this->connection->statement('SET LOCAL idle_in_transaction_session_timeout = '.$this->deadlineMs);

                // Step 2: the claim is the first write of the root Tx (D51); a replay needs no session or module admission.
                if ($spec->commandKey !== null && $digest !== null) {
                    $claim = $this->idempotency->claim($caller, $spec->commandKind, $spec->commandKey, $digest);
                    if ($claim->replay !== null) {
                        $this->assertDepth(1);
                        $this->connection->commit();

                        return $claim->replay;
                    }
                    $claimId = $claim->claimId;
                }

                // Step 3: financial session barrier, module admission, then the body's own planned locks.
                $operation = $this->current = new Operation($this->connection, $caller, $spec, $attempt);
                if ($spec->mode->sharesSessionBarrier()) {
                    $operation->bindSession($this->admission->admitSession($operation->context));
                }
                if ($spec->modules !== []) {
                    $operation->bindModules($this->admission->admitModules($operation->context));
                }

                // Step 4: body behind a savepoint so a stored refusal keeps only its receipt.
                $this->connection->beginTransaction();
                try {
                    $receipt = self::invoke($body, $operation->context);
                    if (! $receipt instanceof Receipt) {
                        throw new LogicException("{$spec->commandKind}: the body must return a Receipt");
                    }
                    $this->assertDepth(2);
                    $this->assertCurrent($operation->context);
                    $this->connection->commit();
                } catch (ProblemException $problem) {
                    if ($problem->problem->receipt() !== 'stored' || $claimId === null) {
                        throw $problem;
                    }
                    // Step 6: stored business refusal — no monetary change, receipt only (D31).
                    $this->assertDepth(2);
                    $this->assertCurrent($operation->context);
                    $this->connection->rollBack(1);
                    $outcome = StoredOutcome::refused($problem, $claimId);
                    $this->idempotency->complete($claimId, $outcome);
                    $this->commit($started, $operation);

                    return $outcome;
                }

                // Step 5: complete the claim and the diagnostic commit log in the same Tx, then commit.
                $outcome = StoredOutcome::accepted($receipt, $claimId);
                if ($claimId !== null) {
                    $this->idempotency->complete($claimId, $outcome);
                }
                if ($spec->commitKind !== null) {
                    $this->admission->appendCommit($operation->context, $spec->commitKind);
                }
                $this->commit($started, $operation);

                return $outcome;
            } catch (Throwable $failure) {
                $operation?->close();
                $this->current = null;
                $this->rollBackAll();
                if ($attempt < $this->maxAttempts && self::retryable($failure)) {
                    continue;
                }

                throw $failure;
            }
        }
    }

    public function assertCurrent(TransactionContext $context, ?string $moduleId = null): void
    {
        $current = $this->current;
        if ($current === null || $current->context !== $context || ! $current->isOpen()) {
            throw new LogicException('Stale or foreign TransactionContext: not the live root of this runner (D60)');
        }
        if ($moduleId !== null) {
            $context->assertParticipant($moduleId, (string) $this->connection->getName());
        }
    }

    private function commit(int $started, Operation $operation): void
    {
        $this->assertDepth(1);
        $this->assertCurrent($operation->context);
        if ((hrtime(true) - $started) / 1_000_000 > $this->deadlineMs) {
            throw new RuntimeException('Root transaction exceeded its hard deadline; rolled back');
        }
        $this->connection->commit();
        $this->assertDepth(0);
        if ($this->connection->getPdo()->inTransaction()) {
            throw new LogicException('Root transaction did not physically commit');
        }
        $this->current = null;
        foreach ($operation->close() as $callback) {
            try {
                $callback();
            } catch (Throwable $exception) {
                // Committed state and its outbox row stand; a failed wake-up is only reported, and a failing reporter
                // must not make a committed root look failed to its caller (who might retry under a new key).
                try {
                    report($exception);
                } catch (Throwable) {
                }
            }
        }
    }

    private function assertDepth(int $expected): void
    {
        if ($this->connection->transactionLevel() !== $expected) {
            throw new LogicException("Unbalanced root transaction: expected depth {$expected}");
        }
    }

    /**
     * The closure type is a promise PHP does not enforce; the caller checks the result at runtime.
     *
     * @param  Closure(TransactionContext): Receipt  $body
     */
    private static function invoke(Closure $body, TransactionContext $context): mixed
    {
        return $body($context);
    }

    private function rollBackAll(): void
    {
        if ($this->connection->transactionLevel() > 0) {
            $this->connection->rollBack(0);
        }
    }

    private static function retryable(Throwable $failure): bool
    {
        if ($failure instanceof LockPlanChanged) {
            return true;
        }
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException) {
                $state = $cause->errorInfo[0] ?? $cause->getCode();

                return in_array($state, self::RETRYABLE, true);
            }
        }

        return false;
    }
}
