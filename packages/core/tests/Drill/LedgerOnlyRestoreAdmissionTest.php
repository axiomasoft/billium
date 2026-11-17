<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Drill;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Exceptions\TransitionRefused;
use Billium\Platform\Tests\PlatformTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Restore drill of the ledger-only profile (D54, D61, D62, REQ-ACC-12). The host controls the attachment generation
 * outside the backup: a restored copy keeps its copied `active` row, yet no financial mutation is admitted until a new
 * epoch is opened in recovery and activated with an external certificate. Raw custody continues meanwhile. Neither a
 * clean seal nor any commit_no comparison activates an owner.
 */
#[Group('D62')]
#[Group('D54')]
#[Group('REQ-ACC-12')]
#[Group('pg')]
final class LedgerOnlyRestoreAdmissionTest extends PlatformTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('billium.modules.composition', 'ledger-only');
        $app['config']->set('billium.modules.enabled', ['platform', 'ledger']);
    }

    public function test_restored_copy_refuses_money_until_recovery_and_certified_activation(): void
    {
        $this->activeSession();
        self::assertTrue($this->money('live')->accepted);
        $liveCommits = $this->rows('billium_money_commit_log');

        // Restore: the host advanced the attachment generation outside the copy and retired the old process tokens.
        $this->tokens->set(FixedSessionAdmission::token(self::INSTALLATION, attachmentGeneration: 2));
        $this->assertRefused(ProblemCode::RecoveryRequired, 'restored copy with the new attachment');
        // A clone whose host gives no valid token refuses everything financial.
        $this->tokens->set(null);
        $this->assertRefused(ProblemCode::InstallationQuarantined, 'clone without host token');
        // Raw custody (immutable input, no financial effect) stays available in both cases.
        self::assertTrue($this->runner()->run($this->spec(key: 'custody-1', mode: FinancialMode::RawCustody, class: ModuleOperationClass::CallbackCustody), static fn (): Receipt => self::receipt())->accepted);

        // Recovery: a new epoch for the new attachment; the copied live epoch is superseded, not sealed.
        $opened = $this->installation()->openSession('open-restore', 'owner-1', 1, 2, 2, 'evidence:restore-barrier');
        self::assertSame(2, $opened->epoch);
        self::assertSame('recovery', $opened->state);
        $old = $this->owner()->selectOne('SELECT state, sealed_at FROM billium_financial_session WHERE epoch = 1');
        self::assertSame('recovery', $old->state);
        self::assertNull($old->sealed_at);

        $this->tokens->set(FixedSessionAdmission::token(self::INSTALLATION, attachmentGeneration: 2, authorityEpoch: 2));
        $this->assertRefused(ProblemCode::RecoveryRequired, 'ordinary money before activation');
        $this->tokens->set(FixedSessionAdmission::token(self::INSTALLATION, attachmentGeneration: 2, authorityEpoch: 2, modes: [FinancialMode::Recovery]));
        $recovery = $this->runner()->run($this->spec(key: 'recovery-1', mode: FinancialMode::Recovery, class: ModuleOperationClass::UnknownResolution), static fn (TransactionContext $context): Receipt => self::receipt(['epoch' => $context->sessionEpoch()]));
        self::assertSame(['epoch' => 2], $recovery->body);

        // The old epoch can never come back; only the latest one is activated, and only with a certificate.
        try {
            $this->installation()->activateSession('activate-old', 1, 'certificate:old', 'admission:old', 'evidence:old');
            self::fail('a superseded epoch is never reactivated');
        } catch (TransitionRefused $refused) {
            self::assertSame('not_the_current_epoch', $refused->reason);
        }
        self::assertSame('42501', self::sqlState(TestEnvironment::pdo(), "UPDATE billium_financial_session SET state = 'active' WHERE epoch = 1"));

        $this->installation()->activateSession('activate-restore', 2, 'certificate:restore', 'admission:restore', 'evidence:reconciled');
        $this->tokens->set(FixedSessionAdmission::token(self::INSTALLATION, attachmentGeneration: 2, authorityEpoch: 2));
        self::assertTrue($this->money('after activation')->accepted);
        // The recovery reducer and the first ordinary root after activation, both under the adopted epoch 2.
        self::assertSame(2, $this->rows('billium_money_commit_log', 'session_epoch = 2 AND commit_no > ?', [$liveCommits]));
        self::assertSame($liveCommits, $this->rows('billium_money_commit_log', 'session_epoch = 1'));

        // A token of the pre-restore attachment is refused for good.
        $this->tokens->set(FixedSessionAdmission::token(self::INSTALLATION));
        $this->assertRefused(ProblemCode::RecoveryRequired, 'pre-restore token');
    }

    public function test_clean_seal_or_equal_commit_numbers_never_activate_an_owner(): void
    {
        $this->activeSession();
        $this->money('before seal');
        $this->installation()->beginSealing('closing', 1);
        $this->installation()->seal('seal', 1);

        // Acquire after a clean release still opens recovery; matching commit numbers are not consulted at all.
        $next = $this->installation()->openSession('open-next', 'owner-2', 2, 1, 3, 'evidence:handover');
        self::assertSame('recovery', $next->state);
        $this->tokens->set(FixedSessionAdmission::token(self::INSTALLATION, 'owner-2', 2, 1, 3));
        $this->assertRefused(ProblemCode::RecoveryRequired, 'no activation by release');
        self::assertSame(1, $this->rows('billium_money_commit_log'));
        self::assertSame('sealed', $this->owner()->selectOne('SELECT state FROM billium_financial_session WHERE epoch = 1')->state);
    }

    private function money(string $ref): StoredOutcome
    {
        return $this->runner()->run($this->spec(key: 'money-'.md5($ref)), static fn (TransactionContext $context): Receipt => self::receipt(['effect' => self::effect($context, $ref)]));
    }

    private function assertRefused(ProblemCode $code, string $case): void
    {
        $before = $this->rows('billium_test_effect');
        try {
            $this->money($case);
            self::fail("{$case} must be refused");
        } catch (ProblemException $problem) {
            self::assertSame($code, $problem->problem, $case);
        }
        self::assertSame($before, $this->rows('billium_test_effect'), $case);
    }
}
