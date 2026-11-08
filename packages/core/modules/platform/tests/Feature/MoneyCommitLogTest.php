<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Receipt;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Tests\PlatformTestCase;
use PHPUnit\Framework\Attributes\Group;

/** D54: money_commit_log is diagnostic, append-only, one row per monetary root Tx with its session epoch. */
#[Group('D54')]
final class MoneyCommitLogTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activeSession();
    }

    public function test_only_committed_monetary_roots_append_with_their_session_epoch(): void
    {
        $this->runner()->run($this->spec(key: 'k-money'), static fn (): Receipt => self::receipt());
        $this->runner()->run($this->spec(key: 'k-money'), static fn (): Receipt => self::receipt()); // replay
        $this->runner()->run($this->spec(key: 'k-plain', commit: null), static fn (): Receipt => self::receipt());
        $this->runner()->run($this->spec(key: 'k-stored'), static fn (): Receipt => throw new ProblemException(ProblemCode::InvoiceFrozen));
        try {
            $this->runner()->run($this->spec(key: 'k-none'), static fn (): Receipt => throw new ProblemException(ProblemCode::ValidationInvalid));
        } catch (ProblemException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame([['kind' => 'ledger.test_effect', 'session_epoch' => 1]], $this->table('SELECT kind, session_epoch FROM billium_money_commit_log ORDER BY commit_no'));
    }

    public function test_a_new_epoch_is_recorded_and_commit_numbers_prove_no_freshness(): void
    {
        $this->runner()->run($this->spec(), static fn (): Receipt => self::receipt());
        $installation = $this->installation();
        $installation->openSession('open-2', 'owner-2', 2, 1, 2, 'evidence:handover');
        $installation->activateSession('activate-2', 2, 'certificate:2', 'admission:2', 'evidence:activate-2');
        $this->tokens->set(FixedSessionAdmission::token(self::INSTALLATION, 'owner-2', 2, 1, 2));
        $this->runner()->run($this->spec(), static fn (): Receipt => self::receipt());

        self::assertSame([['session_epoch' => 1], ['session_epoch' => 2]], $this->table('SELECT session_epoch FROM billium_money_commit_log ORDER BY commit_no'));
        // Nothing reads max(commit_no) for admission: the session row and the host token decide alone.
        self::assertSame('active', $installation->currentSession(self::INSTALLATION)?->state);
    }

    public function test_the_log_is_append_only_for_owner_and_runtime(): void
    {
        $this->runner()->run($this->spec(), static fn (): Receipt => self::receipt());
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(owner: true), 'UPDATE billium_money_commit_log SET session_epoch = 9'));
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(owner: true), 'DELETE FROM billium_money_commit_log'));
        self::assertSame('42501', self::sqlState(TestEnvironment::pdo(), 'DELETE FROM billium_money_commit_log'));
        self::assertSame('23514', self::sqlState(TestEnvironment::pdo(), "INSERT INTO billium_money_commit_log (kind, session_epoch) VALUES ('ledger.x', 0)"));
    }
}
