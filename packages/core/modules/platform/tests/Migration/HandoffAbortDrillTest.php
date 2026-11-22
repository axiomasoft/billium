<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Migration;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\OwnerSessionIdentity;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Platform\Data\Migration\HandoffPlan;
use Billium\Platform\Data\Migration\HandoffRecoveryRequired;
use Billium\Platform\Data\Migration\LedgerScope;
use Billium\Platform\Exceptions\TransitionRefused;
use Billium\Platform\Tests\PlatformTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

#[Group('pg')]
#[Group('P9.7')]
final class HandoffAbortDrillTest extends PlatformTestCase
{
    #[TestWith([false], 'before-target-write')]
    #[TestWith([true], 'after-target-write')]
    public function test_failed_target_activation_requires_proved_safe_source_resume(bool $targetWrote): void
    {
        $this->activeSession();
        $sourceCaller = $this->callers->current();
        $sourceToken = $this->tokens->currentToken(self::INSTALLATION);
        self::assertNotNull($sourceToken);
        $targetCaller = new Caller('seller-a', 'test', 'target-installation', 'target-operator');
        $source = new LedgerScope(new FeedScope(self::INSTALLATION, $sourceCaller->sellerRef, 'test', 'source-lineage'),
            new OwnerSessionIdentity(self::INSTALLATION, 'owner-1', 1, 1, 1), ['receivable'], ['ledger']);
        $target = new LedgerScope(new FeedScope($targetCaller->installationRef, $targetCaller->sellerRef, 'test', 'target-lineage'),
            new OwnerSessionIdentity($targetCaller->installationRef, 'target-owner', 1, 1, 1), ['receivable'], ['ledger']);
        $plan = new HandoffPlan('abort-drill', $source, $target);
        [$handoff, $sourceHost, $targetHost] = MigrationEvidenceTest::fixture(plan: $plan);
        $installation = $this->installation();

        $this->callers->set($targetCaller);
        $installation->init($targetCaller->installationRef);
        $args = ['target-owner', 1, 1, 1, 'target-attachment'];
        $this->authority->issueTransition('open', 'open-target', $args);
        self::assertSame('recovery', $installation->openSession('open-target', ...$args)->state);
        $targetToken = FixedSessionAdmission::token($targetCaller->installationRef, 'target-owner', modes: [FinancialMode::Recovery]);
        $this->tokens->set($targetToken);
        $this->authority->issueProcess($targetToken);
        $handoff->stage($plan);

        $this->callers->set($sourceCaller);
        $this->tokens->set($sourceToken);
        $this->authority->issueTransition('seal', 'source-seal', [1]);
        self::assertSame('sealed', $installation->seal('source-seal', 1)->state);
        $freeze = $handoff->freeze($plan);

        $this->callers->set($targetCaller);
        $this->tokens->set($targetToken);
        if ($targetWrote) {
            $this->applyTargetFact();
        }
        try {
            $installation->activateSession('target-activate', 1, 'unverified-certificate', 'target-attachment', 'unverified');
            self::fail('Target activation succeeded without external evidence');
        } catch (TransitionRefused $refused) {
            self::assertSame('verified_authority_required', $refused->reason);
        }
        self::assertSame('recovery', $installation->currentSession($targetCaller->installationRef)?->state);
        $targetHost->fence = function () use ($targetToken): bool {
            if ($this->rows('billium_money_commit_log', "kind = 'ledger.target_apply'") !== 0) {
                return false;
            }
            // The fixture deployment authority controls token issuance outside the database.
            $this->authority->forgetProcess($targetToken);

            return true;
        };

        $this->callers->set($sourceCaller);
        $this->tokens->set($sourceToken);
        $next = new OwnerSessionIdentity(self::INSTALLATION, 'resumed-source', 2, 1, 2);
        [$retirement, $certificate] = MigrationEvidenceTest::certificates($source, $source, $next);
        // The host accepts only the authorization staged for this exact abort.
        $sourceHost->allowedResume = $certificate;
        // Both cases start with the old source fenced.
        try {
            $this->runner()->run($this->spec(), static fn () => self::fail('Old source session admitted after release'));
        } catch (ProblemException $problem) {
            self::assertSame(ProblemCode::RecoveryRequired, $problem->problem);
        }

        if ($targetWrote) {
            try {
                $handoff->rollback($freeze, $retirement, $certificate);
                self::fail('A committed recovery apply allowed old source resume');
            } catch (HandoffRecoveryRequired $refused) {
                self::assertStringContainsString('zero monetary commits', $refused->getMessage());
            }
            self::assertSame('sealed', $installation->currentSession(self::INSTALLATION)?->state);
            self::assertSame(1, $this->rows('billium_test_effect', "ref = 'target-imported-fact'"));

            return;
        }
        $handoff->rollback($freeze, $retirement, $certificate);
        self::assertTrue($targetHost->fenced);
        $this->callers->set($targetCaller);
        $this->tokens->set($targetToken);
        try {
            $this->applyTargetFact();
            self::fail('Target reacquired admission after the zero-effects proof');
        } catch (ProblemException $problem) {
            self::assertSame(ProblemCode::InstallationQuarantined, $problem->problem);
        }
        $this->callers->set($sourceCaller);
        $args = ['resumed-source', 2, 1, 2, 'target-fenced-no-effects'];
        $this->authority->issueTransition('open', 'open-resumed-source', $args);
        self::assertSame('recovery', $installation->openSession('open-resumed-source', ...$args)->state);
        $args = [2, 'resume-certificate', 'source-admission', 'target-fenced-no-effects'];
        $this->authority->issueTransition('activate', 'activate-resumed-source', $args);
        self::assertSame('active', $installation->activateSession('activate-resumed-source', ...$args)->state);
        $token = FixedSessionAdmission::token(self::INSTALLATION, 'resumed-source', 2, 1, 2);
        $this->tokens->set($token);
        $this->authority->issueProcess($token);
        $this->runner()->run($this->spec(), static fn (TransactionContext $ctx): PlatformReceipt => self::receipt(['epoch' => $ctx->sessionEpoch()]))->orThrow();
        self::assertSame(1, $this->rows('billium_money_commit_log', 'session_epoch = 2'));
        self::assertSame(0, $this->rows('billium_money_commit_log', "kind = 'ledger.target_apply'"));
    }

    private function applyTargetFact(): void
    {
        $this->runner()->run($this->spec(kind: 'ledger.target_apply', mode: FinancialMode::Recovery,
            class: ModuleOperationClass::UnknownResolution, commit: 'ledger.target_apply'),
            static fn (TransactionContext $ctx): PlatformReceipt => self::receipt(['fact' => self::effect($ctx, 'target-imported-fact')]))->orThrow();
    }
}
