<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runtime\FinancialAdmissionToken;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Internal\Authority\AuthorityClaims;
use Billium\Platform\Exceptions\TransitionRefused;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
#[Group('COV-515')]
final class DeploymentOwnerSessionTest extends ExecutionTestCase
{
    public function test_restarted_processes_join_one_owner_only_after_independent_issuance(): void
    {
        foreach (['fpm-a', 'queue-b', 'restarted-a'] as $process) {
            $token = FixedSessionAdmission::token(self::INSTALLATION, processRef: $process);
            $this->tokens->set($token);
            $this->refused();
            $this->authority->issueProcess($token);
            $acquisitions = $this->authority->acquisitions;
            $result = $this->runner()->run($this->spec(financialScope: self::selection(), modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($process) {
                self::effect($ctx, $process);

                return self::receipt(['epoch' => $ctx->sessionEpoch()]);
            });
            self::assertSame(1, $result->body['epoch']);
            self::assertSame($acquisitions, $this->authority->acquisitions, 'No host acquisition under money locks');
            foreach (['billium_financial_session', 'billium_financial_session_event', 'billium_money_commit_log'] as $table) {
                self::assertStringNotContainsString($token->nonce, self::text($this->value("SELECT coalesce(string_agg(t::text,''),'') FROM {$table} t")));
            }
        }
        self::assertSame(1, $this->rows('billium_financial_session'));
        self::assertSame(3, $this->rows('billium_test_effect'));
    }

    public function test_closed_owner_and_recovery_release_acquire_never_self_activate_but_stale_custody_survives(): void
    {
        $this->authority->issueTransition('seal', 'deployment-seal', [1]);
        $this->installation()->seal('deployment-seal', 1);
        $this->refused();
        $this->authority->issueTransition('open', 'deployment-open-2', ['owner-2', 2, 2, 2, 'retired-2']);
        self::assertSame('recovery', $this->installation()->openSession('deployment-open-2', 'owner-2', 2, 2, 2, 'retired-2')->state);
        $token = FixedSessionAdmission::token(self::INSTALLATION, ownerSessionRef: 'owner-2', deploymentGeneration: 2, attachmentGeneration: 2, authorityEpoch: 2);
        $this->tokens->set($token);
        $this->authority->issueProcess($token);
        $this->refused();
        $this->authority->issueTransition('seal', 'recovery-release', [2]);
        try {
            $this->installation()->seal('recovery-release', 2);
            self::fail('Recovery release bypass admitted');
        } catch (TransitionRefused $exception) {
            self::assertSame('session_is_recovery', $exception->reason);
        }
        $this->authority->issueTransition('open', 'deployment-open-3', ['owner-3', 3, 3, 3, 'retired-3']);
        self::assertSame('recovery', $this->installation()->openSession('deployment-open-3', 'owner-3', 3, 3, 3, 'retired-3')->state);
        $token = FixedSessionAdmission::token(self::INSTALLATION, ownerSessionRef: 'owner-3', deploymentGeneration: 3, attachmentGeneration: 3, authorityEpoch: 3);
        $this->tokens->set($token);
        $this->authority->issueProcess($token);
        $this->refused();
        self::assertSame('recovery', $this->installation()->currentSession(self::INSTALLATION)?->state);
        self::assertSame(3, $this->rows('billium_financial_session'));
        $this->tokens->set(null);
        $store = $this->application()->make(ExecutionStore::class);
        $evidence = new ProviderEvidence('stale-external-fact', new ExecutionScope(self::INSTALLATION, 'seller-a', 'test', 'source-a'), 'provider-a', 'account-a', 'event-stale', 'sha256:'.str_repeat('c', 64), 'simulated-verified', 'handler.v1', new DateTimeImmutable, ['actual' => 'captured'], 'secure://fixture/stale-external-fact');
        $this->runner()->run($this->spec(financialScope: self::selection(), mode: FinancialMode::RawCustody, class: ModuleOperationClass::CallbackCustody, modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store, $evidence) {
            $store->appendEvidence($ctx, $evidence);
            $store->enqueueProviderWork($ctx, $evidence->evidenceRef, 'stale-work', 'handler.v1');

            return self::receipt();
        });
        self::assertSame(1, $this->rows('billium_execution_provider_evidence'));
        self::assertSame(1, $this->rows('billium_execution_provider_work'));
        self::assertSame(0, $this->rows('billium_money_commit_log'));
        self::assertSame(0, $this->rows('billium_execution_operation_revision'));
    }

    public function test_copied_active_row_missing_evidence_and_new_attachment_token_refuse(): void
    {
        $token = $this->tokens->currentToken(self::INSTALLATION);
        self::assertNotNull($token);
        $this->authority->forgetProcess($token);
        $this->refused();
        $changed = FixedSessionAdmission::token(self::INSTALLATION, attachmentGeneration: 2);
        $this->tokens->set($changed);
        $this->authority->issueProcess($changed);
        $this->refused();
        self::assertSame('active', $this->installation()->currentSession(self::INSTALLATION)?->state);
        self::assertSame(0, $this->rows('billium_money_commit_log'));
    }

    public function test_source_lineage_uses_full_owner_identity_and_exact_int64_counters_but_not_process_identity(): void
    {
        $token = FixedSessionAdmission::token(self::INSTALLATION, deploymentGeneration: PHP_INT_MAX, attachmentGeneration: PHP_INT_MAX, authorityEpoch: PHP_INT_MAX);
        $lineage = AuthorityClaims::sourceLineage($token, PHP_INT_MAX);
        $restarted = new FinancialAdmissionToken(...array_replace(get_object_vars($token), ['processRef' => 'restarted', 'nonce' => 'new-process-token']));
        self::assertSame($lineage, AuthorityClaims::sourceLineage($restarted, PHP_INT_MAX));
        foreach (['deploymentGeneration', 'attachmentGeneration', 'authorityEpoch'] as $field) {
            $changed = new FinancialAdmissionToken(...array_replace(get_object_vars($token), [$field => PHP_INT_MAX - 1]));
            self::assertNotSame($lineage, AuthorityClaims::sourceLineage($changed, PHP_INT_MAX));
        }
        self::assertNotSame($lineage, AuthorityClaims::sourceLineage($token, PHP_INT_MAX - 1));
    }

    private function refused(): void
    {
        try {
            $this->runner()->run($this->spec(financialScope: self::selection(), modules: ['execution', 'platform']), static fn () => self::receipt());
            self::fail('Unverified/stale owner admitted');
        } catch (ProblemException $exception) {
            self::assertContains($exception->problem, [ProblemCode::InstallationQuarantined, ProblemCode::RecoveryRequired]);
        }
    }

    private static function selection(): FinancialScope
    {
        return new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium');
    }
}
