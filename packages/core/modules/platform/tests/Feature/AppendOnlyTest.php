<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Core\Data\Receipt;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Tests\PlatformTestCase;
use PHPUnit\Framework\Attributes\Group;

/** billium_append_only() and the guarded platform tables reject history rewrites even from the schema owner. */
#[Group('D54')]
#[Group('D55')]
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
        self::assertSame('23514', self::sqlState($runtime, "UPDATE billium_financial_session SET state = 'sealed'"), 'sealed needs sealed_at');
        self::assertNull(self::sqlState($runtime, "UPDATE billium_financial_session SET state = 'sealed', sealed_at = now()"));
        self::assertSame('55000', self::sqlState($runtime, "UPDATE billium_financial_session SET state = 'active'"), 'sealed is terminal');
        self::assertSame('55000', self::sqlState($runtime, 'UPDATE billium_financial_session SET sealed_at = now() - interval \'1 day\''));
        self::assertSame('55000', self::sqlState($runtime, 'UPDATE billium_financial_session SET activation_certificate_ref = \'forged\''));
        // A module row changes only as one lifecycle step with the next generation and a new transition ref.
        self::assertSame('55000', self::sqlState($runtime, "UPDATE billium_module_state SET state = 'retired', generation = generation + 1, last_transition_ref = 'x' WHERE module_id = 'ledger'"));
        self::assertSame('55000', self::sqlState($runtime, "UPDATE billium_module_state SET state = 'draining' WHERE module_id = 'ledger'"));
        self::assertSame('55000', self::sqlState($runtime, "UPDATE billium_module_state SET state = 'draining', generation = generation + 2, last_transition_ref = 'x' WHERE module_id = 'ledger'"));
        self::assertSame('42501', self::sqlState($runtime, "DELETE FROM billium_module_state WHERE module_id = 'ledger'"));
        self::assertSame('55000', self::sqlState($owner, "DELETE FROM billium_module_state WHERE module_id = 'ledger'"));
        self::assertSame(1, $this->value("SELECT generation FROM billium_module_state WHERE module_id = 'ledger'"));
    }
}
