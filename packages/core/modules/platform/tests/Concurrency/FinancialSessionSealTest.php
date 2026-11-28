<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Concurrency;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Exceptions\TransitionRefused;
use Billium\Platform\Internal\Installation\DatabaseInstallation;
use Billium\Platform\Tests\Concurrency\Scenarios\RootOperationScenario;
use Billium\Platform\Tests\PlatformTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Local barrier: every financial root holds financial_session FOR SHARE to commit; a seal takes it FOR UPDATE
 * with a bounded wait. An admitted root completes before the seal; nothing financial is admitted after it.
 */
#[Group('D54')]
#[Group('D62')]
#[Group('D60')]
#[Group('pg')]
final class FinancialSessionSealTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activeSession();
    }

    public function test_seal_waits_for_an_admitted_root_and_then_closes_admission(): void
    {
        $results = $this->race([
            ['action' => 'run', 'key' => 'k-admitted', 'body_sleep_ms' => 1500],
            ['action' => 'seal', 'ref' => 'seal-1', 'epoch' => 1, 'delay_ms' => 500],
        ]);
        [$run, $seal] = [self::ok($results[0]), self::ok($results[1])];
        self::assertTrue($run['accepted'], json_encode($results, JSON_THROW_ON_ERROR));
        self::assertSame('sealed', $seal['state']);
        self::assertGreaterThanOrEqual(600, $seal['elapsed_ms'], 'the seal waited for the admitted root');
        self::assertSame(1, $this->rows('billium_money_commit_log', 'session_epoch = 1'));

        $later = $this->value('SELECT sealed_at > (SELECT max(written_at) FROM billium_test_effect) FROM billium_financial_session WHERE epoch = 1');
        self::assertTrue($later, 'the admitted effect precedes the seal');

        try {
            $this->runner()->run($this->spec(key: 'k-after'), static fn (): Receipt => self::receipt());
            self::fail('a sealed session admits no financial mutation');
        } catch (ProblemException $problem) {
            self::assertSame(ProblemCode::RecoveryRequired, $problem->problem);
        }
        // Raw custody is outside the monetary barrier and continues after the seal.
        self::assertTrue($this->runner()->run($this->spec(key: 'k-custody', mode: FinancialMode::RawCustody, modules: []), static fn (): Receipt => self::receipt())->accepted);
        self::assertSame(1, $this->rows('billium_financial_session_event', "transition = 'sealed'"));
    }

    public function test_seal_lock_timeout_refuses_without_asserting_a_seal(): void
    {
        config(['billium.financial_session.seal_lock_timeout_ms' => 200]);
        $this->application()->forgetInstance(BilliumOptions::class);
        $this->application()->forgetInstance(DatabaseInstallation::class);
        $holder = TestEnvironment::pdo();
        $holder->beginTransaction();
        // An admitted root (or a stuck worker) holds the session row FOR SHARE.
        self::pdoValue($holder, 'SELECT id FROM billium_financial_session WHERE epoch = 1 FOR SHARE');
        try {
            SimulatedFinancialAuthorityEvidence::install($this->application())->issueTransition('seal', 'seal-timeout', [1]);
            $this->installation()->seal('seal-timeout', 1);
            self::fail('seal must not wait past its bound');
        } catch (TransitionRefused $refused) {
            self::assertSame('seal_lock_timeout', $refused->reason);
        } finally {
            $holder->commit();
        }
        self::assertSame('active', $this->installation()->currentSession(self::INSTALLATION)?->state);
        self::assertSame(0, $this->rows('billium_idempotency_receipt', 'command_key = ?', ['seal-timeout']));

        // Once the drain bound holds, the same transition ref seals.
        SimulatedFinancialAuthorityEvidence::install($this->application())->issueTransition('seal', 'seal-timeout', [1]);
        self::assertSame('sealed', $this->installation()->seal('seal-timeout', 1)->state);
        SimulatedFinancialAuthorityEvidence::install($this->application())->issueTransition('seal', 'seal-timeout', [1]);
        self::assertSame('sealed', $this->installation()->seal('seal-timeout', 1)->state, 'replay returns the receipt');
    }

    public function test_sealing_closes_admission_before_the_seal(): void
    {
        SimulatedFinancialAuthorityEvidence::install($this->application())->issueTransition('sealing', 'closing-1', [1]);
        self::assertSame('sealing', $this->installation()->beginSealing('closing-1', 1)->state);
        foreach ([FinancialMode::Active, FinancialMode::Recovery] as $mode) {
            try {
                $this->runner()->run($this->spec(mode: $mode), static fn (): Receipt => self::receipt());
                self::fail("{$mode->value} is not admitted while sealing");
            } catch (ProblemException $problem) {
                self::assertContains($problem->problem, [ProblemCode::RecoveryRequired, ProblemCode::InstallationQuarantined]);
            }
        }
        self::assertSame(0, $this->rows('billium_money_commit_log'));
        SimulatedFinancialAuthorityEvidence::install($this->application())->issueTransition('seal', 'seal-2', [1]);
        self::assertSame('sealed', $this->installation()->seal('seal-2', 1)->state);
        try {
            SimulatedFinancialAuthorityEvidence::install($this->application())->issueTransition('seal', 'seal-3', [1]);
            $this->installation()->seal('seal-3', 1);
            self::fail('a sealed session cannot be sealed again');
        } catch (TransitionRefused $refused) {
            self::assertSame('session_is_sealed', $refused->reason);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $workers
     * @return list<array{index: int, ok?: array<string, mixed>, error?: array{code: string}}>
     */
    private function race(array $workers): array
    {
        return ConcurrencyStand::run(RootOperationScenario::class, count($workers), ['workers' => $workers], 30);
    }
}
