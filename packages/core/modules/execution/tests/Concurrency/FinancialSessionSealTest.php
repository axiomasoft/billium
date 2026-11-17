<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Concurrency;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Exceptions\TransitionRefused;
use Billium\Platform\Internal\Installation\DatabaseInstallation;
use Billium\Platform\Tests\Concurrency\Scenarios\RootOperationScenario;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
#[Group('COV-515')]
#[Group('RT-10')]
final class FinancialSessionSealTest extends ExecutionTestCase
{
    public function test_real_processes_hold_shared_session_until_commit_then_seal_blocks_every_execution_root(): void
    {
        $results = ConcurrencyStand::run(RootOperationScenario::class, 3, ['workers' => [
            ['action' => 'run', 'key' => 'execution-worker-a', 'modules' => ['execution', 'platform'], 'kind' => 'execution.authority_test', 'body_sleep_ms' => 1400],
            ['action' => 'run', 'key' => 'execution-worker-b', 'modules' => ['execution', 'platform'], 'kind' => 'execution.authority_test', 'body_sleep_ms' => 1100],
            ['action' => 'seal', 'ref' => 'execution-seal', 'epoch' => 1, 'delay_ms' => 400],
        ]]);
        $workers = array_map(self::ok(...), $results);
        self::assertCount(3, array_unique(array_column($workers, 'process_id')));
        self::assertCount(3, array_unique(array_column($workers, 'backend_pid')));
        self::assertTrue($workers[0]['accepted']);
        self::assertTrue($workers[1]['accepted']);
        self::assertSame('sealed', $workers[2]['state']);
        self::assertGreaterThanOrEqual(600, $workers[2]['elapsed_ms']);
        self::assertTrue($this->value('SELECT sealed_at > (SELECT max(written_at) FROM billium_test_effect) FROM billium_financial_session WHERE epoch=1'));
        self::assertSame(2, $this->rows('billium_money_commit_log', 'session_epoch=1'));
        foreach ([ModuleOperationClass::NewIntent, ModuleOperationClass::ObservedFactRecording, ModuleOperationClass::FrozenIntentCompletion] as $class) {
            try {
                $this->runner()->run($this->spec(financialScope: self::selection(), class: $class, modules: ['execution', 'platform']), static function (TransactionContext $ctx) {
                    self::effect($ctx, 'after-seal');

                    return self::receipt();
                });
                self::fail('Sealed root admitted');
            } catch (ProblemException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        self::assertSame(2, $this->rows('billium_test_effect'));
    }

    public function test_bounded_failed_drain_stays_closing_and_receipts_cannot_claim_a_seal(): void
    {
        $this->authority->issueTransition('sealing', 'execution-closing', [1]);
        $this->installation()->beginSealing('execution-closing', 1);
        config(['billium.financial_session.seal_lock_timeout_ms' => 150]);
        $this->application()->forgetInstance(DatabaseInstallation::class);
        $holder = TestEnvironment::pdo();
        $holder->beginTransaction();
        self::pdoValue($holder, 'SELECT id FROM billium_financial_session WHERE epoch=1 FOR SHARE');
        self::assertNotSame(self::pdoValue($holder, 'SELECT pg_backend_pid()'), $this->value('SELECT pg_backend_pid()'));
        try {
            $this->authority->issueTransition('seal', 'bounded-seal', [1]);
            $this->installation()->seal('bounded-seal', 1);
            self::fail('Seal ignored admitted holder');
        } catch (TransitionRefused $exception) {
            self::assertSame('seal_lock_timeout', $exception->reason);
        } finally {
            $holder->rollBack();
        }
        self::assertSame('sealing', $this->installation()->currentSession(self::INSTALLATION)?->state);
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_key='bounded-seal'"));
        self::assertTrue($this->runner()->run($this->spec(financialScope: self::selection(), mode: FinancialMode::RawCustody, class: ModuleOperationClass::CallbackCustody, modules: ['execution', 'platform']), static fn () => self::receipt())->accepted);
        self::assertSame('sealed', $this->installation()->seal('bounded-seal', 1)->state);
    }

    private static function selection(): FinancialScope
    {
        return new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium');
    }
}
