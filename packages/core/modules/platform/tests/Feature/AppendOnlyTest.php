<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Tests\PlatformTestCase;
use PHPUnit\Framework\Attributes\Group;

/** billium_append_only() and the guarded platform tables reject history rewrites even from the schema owner. */
#[Group('D54')]
#[Group('D55')]
#[Group('pg')]
final class AppendOnlyTest extends PlatformTestCase
{
    public function test_append_only_tables_reject_update_and_delete(): void
    {
        $this->activeSession();
        $this->runner()->run($this->spec(), static fn (): Receipt => self::receipt());
        $owner = TestEnvironment::pdo(owner: true);
        $runtime = TestEnvironment::pdo();
        self::assertSame(1, $this->rows('pg_proc', "proname = 'billium_append_only'"));
        foreach ([
            'billium_money_commit_log' => 'session_epoch = session_epoch',
            'billium_module_transitions' => 'reason = reason',
            'billium_financial_session_event' => 'actor_ref = actor_ref',
        ] as $table => $set) {
            self::assertGreaterThan(0, $this->rows($table), $table);
            self::assertSame('55000', self::sqlState($owner, "UPDATE {$table} SET {$set}"), $table);
            self::assertSame('55000', self::sqlState($owner, "DELETE FROM {$table}"), $table);
            self::assertSame('42501', self::sqlState($runtime, "DELETE FROM {$table}"), $table);
            self::assertSame('42501', self::sqlState($runtime, "UPDATE {$table} SET {$set}"), $table);
        }
    }

    public function test_session_and_module_guards_allow_only_their_transitions(): void
    {
        $this->activeSession();
        $runtime = TestEnvironment::pdo();
        $owner = TestEnvironment::pdo(owner: true);
        // Identity and generations of an adopted session never change; illegal steps are refused.
        self::assertSame('42501', self::sqlState($runtime, 'UPDATE billium_financial_session SET authority_epoch = 2'));
        self::assertSame('55000', self::sqlState($owner, 'UPDATE billium_financial_session SET authority_epoch = 2'));
        self::assertSame('42501', self::sqlState($runtime, 'DELETE FROM billium_financial_session'));
        self::assertSame('55000', self::sqlState($owner, 'DELETE FROM billium_financial_session'));
        self::assertSame('42501', self::sqlState($runtime, "UPDATE billium_financial_session SET state = 'sealed'"));
        self::assertSame('42501', self::sqlState($runtime, "UPDATE billium_financial_session SET state = 'sealed', sealed_at = now()"));
        $sealed = $this->installation()->seal('seal-guard-test', 1);
        self::assertSame('sealed', $sealed->state);
        self::assertNotNull($sealed->sealedAt);
        self::assertSame(1, $this->rows('billium_financial_session_event', "transition='sealed'"));
        self::assertSame('55000', self::sqlState($owner, "UPDATE billium_financial_session SET state = 'active'"), 'sealed is terminal');
        self::assertSame('42501', self::sqlState($runtime, 'UPDATE billium_financial_session SET sealed_at = now() - interval \'1 day\''));
        self::assertSame('42501', self::sqlState($runtime, 'UPDATE billium_financial_session SET activation_certificate_ref = \'forged\''));
        // A module row changes only as one lifecycle step with the next generation and a new transition ref.
        self::assertSame('55000', self::sqlState($runtime, "UPDATE billium_module_state SET state = 'retired', generation = generation + 1, last_transition_ref = 'x' WHERE module_id = 'ledger'"));
        self::assertSame('55000', self::sqlState($runtime, "UPDATE billium_module_state SET state = 'draining' WHERE module_id = 'ledger'"));
        self::assertSame('55000', self::sqlState($runtime, "UPDATE billium_module_state SET state = 'draining', generation = generation + 2, last_transition_ref = 'x' WHERE module_id = 'ledger'"));
        self::assertSame('42501', self::sqlState($runtime, "DELETE FROM billium_module_state WHERE module_id = 'ledger'"));
        self::assertSame('55000', self::sqlState($owner, "DELETE FROM billium_module_state WHERE module_id = 'ledger'"));
        self::assertSame(1, $this->value("SELECT generation FROM billium_module_state WHERE module_id = 'ledger'"));
    }

    public function test_runtime_cannot_forge_an_active_epoch_and_the_guard_requires_recovery_on_insert(): void
    {
        $this->activeSession();
        $runtime = TestEnvironment::pdo();
        self::assertSame('42501', self::sqlState($runtime, "UPDATE billium_financial_session SET state='recovery'"));
        $forged = <<<'SQL'
            INSERT INTO billium_financial_session(installation_ref,epoch,external_owner_session_ref,deployment_generation,
                external_attachment_generation,authority_epoch,state,activation_certificate_ref)
            VALUES('inst-test',2,'owner-1',1,1,1,'active','forged-certificate')
            SQL;
        self::assertSame('42501', self::sqlState($runtime, $forged));
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(owner: true), $forged));
        self::assertSame(1, $this->rows('billium_financial_session'));
        self::assertSame(2, $this->rows('billium_financial_session_event'));
        self::assertTrue($this->runner()->run($this->spec(), static fn (): Receipt => self::receipt())->accepted);
        $this->installation()->seal('seal-before-next-epoch', 1);
        $opened = $this->installation()->openSession('open-next', 'owner-1', 1, 1, 1, 'evidence:open-next');
        self::assertSame('recovery', $opened->state);
        try {
            $this->runner()->run($this->spec(), static fn (): Receipt => self::receipt());
            self::fail('An opened recovery epoch cannot admit Active money');
        } catch (ProblemException $refusal) {
            self::assertSame(ProblemCode::RecoveryRequired, $refusal->problem);
        }
        self::assertSame('active', $this->installation()->activateSession('activate-next', 2, 'certificate:2', 'admission:2', 'evidence:2')->state);
        self::assertSame(1, $this->rows('billium_financial_session_event', "transition='activated' AND session_id IN (SELECT id FROM billium_financial_session WHERE epoch=2)"));
    }
}
