<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Platform\Contracts\Outbox\Outbox;
use Billium\Platform\Data\Outbox\OutboxEvent;
use Billium\Platform\Tests\PlatformTestCase;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

#[Group('M-55')]
#[Group('D31')]
#[Group('pg')]
final class OperationRunnerTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activeSession();
    }

    public function test_accepted_operation_commits_state_receipt_and_commit_log_together(): void
    {
        $outcome = $this->runner()->run($this->spec(key: 'k-1'), static function (TransactionContext $context): Receipt {
            self::effect($context, 'accepted');

            return self::receipt(['epoch' => $context->sessionEpoch(), 'modules' => $context->admittedModules()]);
        });

        self::assertTrue($outcome->accepted);
        self::assertFalse($outcome->replayed);
        self::assertSame(['epoch' => 1, 'modules' => ['ledger' => 1, 'platform' => 1]], $outcome->body);
        self::assertSame(1, $this->rows('billium_test_effect'));
        self::assertSame(1, $this->rows('billium_idempotency_receipt', "outcome = 'accepted' AND id = ?", [$outcome->receiptId]));
        self::assertSame(1, $this->rows('billium_money_commit_log', "kind = 'ledger.test_effect' AND session_epoch = 1"));
        self::assertSame(0, self::connection('pgsql')->transactionLevel());
    }

    public function test_stored_refusal_keeps_only_its_receipt_and_replays(): void
    {
        $body = static function (TransactionContext $context): Receipt {
            self::effect($context, 'rolled back to the savepoint');

            throw new ProblemException(ProblemCode::RefundCapacityExceeded, 'no capacity');
        };
        $refused = $this->runner()->run($this->spec(key: 'k-stored'), $body);

        self::assertFalse($refused->accepted);
        self::assertSame(ProblemCode::RefundCapacityExceeded, $refused->problem);
        self::assertSame(0, $this->rows('billium_test_effect'));
        self::assertSame(0, $this->rows('billium_money_commit_log'));
        self::assertSame(1, $this->rows('billium_idempotency_receipt', "outcome = 'refused'"));

        $replay = $this->runner()->run($this->spec(key: 'k-stored'), static fn (): Receipt => throw new LogicException('a replay never runs the body'));
        self::assertTrue($replay->replayed);
        self::assertSame($refused->receiptId, $replay->receiptId);
        self::assertSame(ProblemCode::RefundCapacityExceeded, $replay->problem);
        $this->expectException(ProblemException::class);
        $replay->orThrow();
    }

    #[TestWith(['begin', false])]
    #[TestWith(['begin', true])]
    #[TestWith(['commit', false])]
    #[TestWith(['rollback', false])]
    public function test_an_unbalanced_body_rolls_back_the_entire_root(string $imbalance, bool $refuse): void
    {
        $callbacks = 0;
        try {
            $this->runner()->run($this->spec(key: 'k-unbalanced'), function (TransactionContext $context) use ($imbalance, $refuse, &$callbacks): Receipt {
                self::effect($context, 'unbalanced');
                $this->application()->make(Outbox::class)->record($context, new OutboxEvent(
                    '2F0KAKV3DPPEMP9HXV300WF6C0', 'obligation.created.v1', 'billium.event.v1', 'x:1', 1, new DateTimeImmutable, [],
                ));
                $context->afterCommit(static function () use (&$callbacks): void {
                    $callbacks++;
                });
                $db = self::connection($context->connectionName());
                match ($imbalance) {
                    'begin' => $db->beginTransaction(),
                    'commit' => $db->commit(),
                    'rollback' => $db->rollBack(),
                    default => throw new LogicException('Unknown test imbalance'),
                };
                if ($refuse) {
                    throw new ProblemException(ProblemCode::RefundCapacityExceeded);
                }

                return self::receipt();
            });
            self::fail('unbalanced bodies cannot return an accepted or stored outcome');
        } catch (LogicException $exception) {
            self::assertStringContainsString('Unbalanced root transaction', $exception->getMessage());
        }
        self::assertSame(0, $callbacks);
        self::assertSame(0, self::connection('pgsql')->transactionLevel());
        self::assertFalse(self::connection('pgsql')->getPdo()->inTransaction());
        self::assertSame(0, $this->rows('billium_test_effect'));
        self::assertSame(0, $this->rows('billium_idempotency_receipt', 'command_key = ?', ['k-unbalanced']));
        self::assertSame(0, $this->rows('billium_outbox_event'));
        self::assertSame(0, $this->rows('billium_money_commit_log'));
    }

    public function test_callbacks_run_only_after_the_physical_root_commit(): void
    {
        $callbacks = 0;
        $this->runner()->run($this->spec(key: 'k-callback-commit'), function (TransactionContext $context) use (&$callbacks): Receipt {
            $db = self::connection($context->connectionName());
            $db->beginTransaction();
            self::effect($context, 'discarded savepoint');
            $db->rollBack();
            $db->transaction(static function () use ($context): void {
                $context->assertParticipant('ledger');
                self::effect($context, 'visible');
            });
            $context->afterCommit(function () use ($context, &$callbacks): void {
                self::assertFalse($context->isActive());
                self::assertSame(0, self::connection('pgsql')->transactionLevel());
                self::assertFalse(self::connection('pgsql')->getPdo()->inTransaction());
                // This is the independent owner connection, so it sees only physically committed effects/receipts.
                self::assertSame(1, $this->rows('billium_test_effect'));
                self::assertSame(1, $this->rows('billium_idempotency_receipt', 'command_key = ?', ['k-callback-commit']));
                $callbacks++;
            });

            return self::receipt();
        });
        self::assertSame(1, $callbacks, 'callback assertions must all have completed');
    }

    public function test_none_policy_refusal_rolls_back_the_claim_so_the_same_key_can_be_corrected(): void
    {
        try {
            $this->runner()->run($this->spec(key: 'k-none', digest: 'sha256:'.str_repeat('1', 64)), static function (TransactionContext $context): Receipt {
                self::effect($context, 'never committed');

                throw new ProblemException(ProblemCode::ValidationInvalid, 'bad envelope');
            });
            self::fail('A none-policy refusal is thrown');
        } catch (ProblemException $problem) {
            self::assertSame(ProblemCode::ValidationInvalid, $problem->problem);
        }
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind NOT LIKE 'platform.%'"));
        self::assertSame(0, $this->rows('billium_test_effect'));

        // D31: the receipt was not stored, so the same key with a corrected payload is a new command.
        $outcome = $this->runner()->run($this->spec(key: 'k-none', digest: 'sha256:'.str_repeat('2', 64)), static fn (): Receipt => self::receipt());
        self::assertTrue($outcome->accepted);
        self::assertFalse($outcome->replayed);
    }

    public function test_serialization_and_deadlock_failures_retry_only_database_work(): void
    {
        foreach (['40001', '40P01'] as $state) {
            $attempts = 0;
            $effects = 0;
            $outcome = $this->runner()->run($this->spec(key: "k-retry-{$state}"), function (TransactionContext $context) use (&$attempts, &$effects, $state): Receipt {
                $attempts++;
                self::effect($context, "attempt {$context->attempt()}");
                // A network effect may only be woken after commit: it must run exactly once (COV-288).
                $context->afterCommit(function () use (&$effects): void {
                    $effects++;
                });
                if ($context->attempt() === 1) {
                    self::connection($context->connectionName())->unprepared("DO \$\$ BEGIN RAISE EXCEPTION 'injected' USING ERRCODE = '{$state}'; END \$\$");
                }

                return self::receipt(['attempt' => $context->attempt()]);
            });
            self::assertSame(2, $attempts, $state);
            self::assertSame(1, $effects, $state);
            self::assertSame(['attempt' => 2], $outcome->body);
            self::assertSame(1, $this->rows('billium_test_effect', 'ref = ?', ['attempt 2']));
            self::assertSame(0, $this->rows('billium_test_effect', 'ref = ?', ['attempt 1']));
            self::assertSame(1, $this->rows('billium_idempotency_receipt', 'command_key = ?', ["k-retry-{$state}"]));
            $this->owner()->unprepared('TRUNCATE billium_test_effect');
        }
    }

    public function test_lock_plan_change_retries_with_a_new_context(): void
    {
        $contexts = [];
        $outcome = $this->runner()->run($this->spec(), function (TransactionContext $context) use (&$contexts): Receipt {
            $contexts[] = $context;
            if (count($contexts) === 1) {
                throw new LockPlanChanged('row set changed');
            }
            self::assertFalse($contexts[0]->isActive(), 'the first attempt context is dead');

            return self::receipt();
        });
        self::assertTrue($outcome->accepted);
        self::assertCount(2, $contexts);
        self::assertNotSame($contexts[0]->rootRef(), $contexts[1]->rootRef());
        self::assertFalse($contexts[1]->isActive());
    }

    public function test_retry_budget_is_bounded(): void
    {
        $attempts = 0;
        try {
            $this->runner()->run($this->spec(key: 'k-budget'), function (TransactionContext $context) use (&$attempts): Receipt {
                $attempts++;
                self::effect($context, 'never');
                self::connection($context->connectionName())->unprepared("DO \$\$ BEGIN RAISE EXCEPTION 'injected' USING ERRCODE = '40001'; END \$\$");

                return self::receipt();
            });
            self::fail('exhausted budget is red');
        } catch (QueryException $exception) {
            self::assertSame('40001', $exception->errorInfo[0] ?? null);
        }
        self::assertSame(3, $attempts);
        self::assertSame(0, $this->rows('billium_test_effect'));
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind NOT LIKE 'platform.%'"));
    }

    public function test_the_runner_is_root_only(): void
    {
        $db = self::connection('pgsql');
        try {
            $db->transaction(fn (): mixed => $this->runner()->run($this->spec(), static fn (): Receipt => self::receipt()));
            self::fail('a runner inside a host transaction is refused');
        } catch (LogicException $exception) {
            self::assertStringContainsString('root-only', $exception->getMessage());
        }

        try {
            $this->runner()->run($this->spec(key: 'k-outer'), function (TransactionContext $context): Receipt {
                self::effect($context, 'outer');
                $this->runner()->run($this->spec(key: 'k-inner'), static fn (): Receipt => self::receipt());

                return self::receipt();
            });
            self::fail('a nested runner is refused');
        } catch (LogicException $exception) {
            self::assertStringContainsString('root-only', $exception->getMessage());
        }
        self::assertSame(0, $this->rows('billium_test_effect'));
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind NOT LIKE 'platform.%'"));
    }

    public function test_seller_or_environment_other_than_the_caller_is_denied_before_any_write(): void
    {
        foreach ([['seller-b', null], [null, 'live']] as [$seller, $environment]) {
            try {
                $this->runner()->run($this->spec(key: 'k-auth', seller: $seller, environment: $environment), static fn (): Receipt => self::receipt());
                self::fail('foreign scope must be denied');
            } catch (ProblemException $problem) {
                self::assertSame(ProblemCode::AuthDenied, $problem->problem);
            }
        }
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind NOT LIKE 'platform.%'"));

        // Replay re-checks the current caller: another seller never sees seller-a's receipt.
        $this->runner()->run($this->spec(key: 'k-auth'), static fn (): Receipt => self::receipt(['secret' => 'seller-a']));
        $this->callers->as('seller-b');
        try {
            $this->runner()->run($this->spec(key: 'k-auth', seller: 'seller-a'), static fn (): Receipt => self::receipt());
            self::fail('replay under another caller must be denied');
        } catch (ProblemException $problem) {
            self::assertSame(ProblemCode::AuthDenied, $problem->problem);
        }
    }

    public function test_context_expires_after_commit_and_wakeup_failure_keeps_the_commit(): void
    {
        $captured = null;
        $this->runner()->run($this->spec(), static function (TransactionContext $context) use (&$captured): Receipt {
            $captured = $context;
            self::effect($context, 'kept');
            $context->afterCommit(static fn () => throw new RuntimeException('wake-up failed'));

            return self::receipt();
        });
        self::assertSame(1, $this->rows('billium_test_effect'));
        self::assertInstanceOf(TransactionContext::class, $captured);
        self::assertFalse($captured->isActive());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Stale TransactionContext');
        $captured->lock(new LockRequest(LockRank::Obligation, 'billium_test_effect', [1]));
    }

    public function test_unkeyed_stored_refusal_is_thrown_and_body_must_return_a_receipt(): void
    {
        try {
            $this->runner()->run($this->spec(), static function (TransactionContext $context): Receipt {
                self::effect($context, 'gone');

                throw new ProblemException(ProblemCode::InvoiceFrozen);
            });
            self::fail('an unkeyed stored refusal has nowhere to be stored');
        } catch (ProblemException $problem) {
            self::assertSame(ProblemCode::InvoiceFrozen, $problem->problem);
        }
        self::assertSame(0, $this->rows('billium_test_effect'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must return a Receipt');
        // @phpstan-ignore argument.type (proves the runtime guard against a non-receipt body)
        $this->runner()->run($this->spec(), static fn (): string => 'not a receipt');
    }

    public function test_raw_custody_runs_without_session_and_without_a_commit_log(): void
    {
        $this->tokens->set(null);
        $outcome = $this->runner()->run(
            $this->spec(key: 'k-custody', mode: FinancialMode::RawCustody, modules: []),
            static fn (TransactionContext $context): Receipt => self::receipt(['effect' => self::effect($context, 'custody')]),
        );
        self::assertTrue($outcome->accepted);
        self::assertSame(0, $this->rows('billium_money_commit_log'));
    }
}
