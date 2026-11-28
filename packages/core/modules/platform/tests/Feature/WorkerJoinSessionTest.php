<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Platform\Tests\Concurrency\Scenarios\RootOperationScenario;
use Billium\Platform\Tests\PlatformTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The OwnerSession belongs to the deployment, not to a PHP/queue process. Workers join it with short-lived host
 * tokens; restart and scaling inside the session are not a transfer. Any other owner/deployment/attachment/epoch, an
 * expired or foreign token, or no token refuses before any financial effect.
 */
#[Group('D62')]
#[Group('pg')]
final class WorkerJoinSessionTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activeSession();
    }

    public function test_many_worker_processes_join_the_same_session_without_recovery(): void
    {
        $results = ConcurrencyStand::run(RootOperationScenario::class, 4, ['body_sleep_ms' => 50]);
        foreach ($results as $result) {
            self::assertTrue($result['ok']['accepted'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        }
        self::assertCount(4, array_unique(array_column(array_column($results, 'ok'), 'process_id')));
        self::assertCount(4, array_unique(array_column(array_column($results, 'ok'), 'backend_pid')));
        self::assertSame(4, $this->rows('billium_money_commit_log', 'session_epoch = 1'));
        self::assertSame(1, $this->rows('billium_financial_session'), 'joining never opens a session');
        self::assertSame('active', $this->installation()->currentSession(self::INSTALLATION)?->state);
    }

    public function test_a_restarted_worker_with_a_new_token_joins_the_same_epoch(): void
    {
        foreach (['process-1', 'process-1-restarted', 'queue-7'] as $process) {
            $token = FixedSessionAdmission::token(self::INSTALLATION, processRef: $process);
            $this->tokens->set($token);
            SimulatedFinancialAuthorityEvidence::admitCurrent($this->application());
            $epoch = $this->runner()->run($this->spec(), static fn (TransactionContext $context): Receipt => self::receipt(['epoch' => $context->sessionEpoch()]))->body['epoch'];
            self::assertSame(1, $epoch, $process);
            // The token value and its nonce never reach the database.
            foreach (['billium_financial_session', 'billium_financial_session_event', 'billium_idempotency_receipt', 'billium_money_commit_log'] as $table) {
                $dump = self::text($this->value("SELECT coalesce(string_agg(t::text, ''), '') FROM {$table} t"));
                self::assertStringNotContainsString($token->nonce, $dump, $table);
                self::assertStringNotContainsString($process, $dump, $table);
            }
        }
        self::assertSame(1, $this->rows('billium_financial_session'));
    }

    public function test_tokens_of_another_owner_session_generation_or_epoch_refuse(): void
    {
        foreach ([
            ['ownerSessionRef' => 'owner-other'],
            ['deploymentGeneration' => 2],
            ['attachmentGeneration' => 2],
            ['authorityEpoch' => 2],
        ] as $claims) {
            $this->tokens->set(FixedSessionAdmission::fromArray($claims + ['installationRef' => self::INSTALLATION]));
            SimulatedFinancialAuthorityEvidence::admitCurrent($this->application());
            $this->assertRefused(ProblemCode::RecoveryRequired, json_encode($claims, JSON_THROW_ON_ERROR));
        }
    }

    public function test_missing_expired_foreign_or_mode_limited_tokens_refuse(): void
    {
        $this->tokens->set(null);
        $this->assertRefused(ProblemCode::InstallationQuarantined, 'no token');
        $this->tokens->set(FixedSessionAdmission::token(self::INSTALLATION, expiresIn: '-1 second'));
        SimulatedFinancialAuthorityEvidence::admitCurrent($this->application());
        $this->assertRefused(ProblemCode::InstallationQuarantined, 'expired');
        $this->tokens->set(FixedSessionAdmission::token('inst-other'));
        SimulatedFinancialAuthorityEvidence::admitCurrent($this->application());
        $this->assertRefused(ProblemCode::InstallationQuarantined, 'foreign installation');
        $this->tokens->set(FixedSessionAdmission::token(self::INSTALLATION, modes: [FinancialMode::Recovery]));
        SimulatedFinancialAuthorityEvidence::admitCurrent($this->application());
        $this->assertRefused(ProblemCode::InstallationQuarantined, 'recovery-only token');
        self::assertSame(0, $this->rows('billium_test_effect'));
        self::assertSame(0, $this->rows('billium_money_commit_log'));
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind NOT LIKE 'platform.%'"));
    }

    private function assertRefused(ProblemCode $code, string $case): void
    {
        try {
            $this->runner()->run($this->spec(key: 'k-'.md5($case)), static fn (TransactionContext $context): Receipt => self::receipt(['effect' => self::effect($context, $case)]));
            self::fail("{$case} must be refused");
        } catch (ProblemException $problem) {
            self::assertSame($code, $problem->problem, $case);
        }
    }
}
