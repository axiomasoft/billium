<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Schema;

use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Internal\Repositories\LedgerPrivileges;
use Illuminate\Config\Repository;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class LedgerBackstopTest extends LedgerTestCase
{
    public function test_every_ledger_table_denies_runtime_raw_dml_and_all_journals_are_append_only(): void
    {
        $runtime = TestEnvironment::pdo();
        $owner = TestEnvironment::pdo(owner: true);
        $tables = $this->table("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'billium_%' AND tablename NOT IN (SELECT tablename FROM pg_tables WHERE tablename LIKE 'billium_test%')");
        $ledger = ['obligation', 'adjustment', 'customer_credit', 'settlement_intent', 'settlement_target', 'settlement_intent_closure', 'invoice', 'invoice_line', 'observed_payment', 'observed_payment_revision', 'payment', 'payment_revision', 'allocation', 'allocation_receipt', 'credit_note', 'ledger_discrepancy', 'discrepancy_event'];
        foreach ($ledger as $name) {
            self::assertSame('42501', self::sqlState($runtime, 'INSERT INTO public.billium_'.$name.' DEFAULT VALUES'), $name);
            self::assertSame('42501', self::sqlState($runtime, 'DELETE FROM public.billium_'.$name), $name);
            self::assertSame('42501', self::sqlState($runtime, 'UPDATE public.billium_'.$name.' SET installation_ref=installation_ref'), $name);
            self::assertSame('42501', self::sqlState($runtime, 'TRUNCATE public.billium_'.$name), $name);
        }
        self::assertGreaterThanOrEqual(count($ledger), count($tables));
        $this->obligation();
        foreach (['adjustment', 'allocation', 'allocation_receipt', 'settlement_target', 'settlement_intent_closure', 'payment_revision', 'observed_payment_revision', 'credit_note', 'ledger_discrepancy', 'discrepancy_event'] as $name) {
            self::assertSame(1, $this->rows('pg_trigger', "tgrelid='public.billium_{$name}'::regclass AND tgname='billium_{$name}_append_only' AND NOT tgisinternal"));
        }
        self::assertSame('55000', self::sqlState($owner, "UPDATE billium_allocation_receipt SET kind='rewritten'"));
        self::assertSame('55000', self::sqlState($owner, 'DELETE FROM billium_allocation_receipt'));
    }

    public function test_immutable_terms_and_money_checks_reject_raw_sql(): void
    {
        $this->obligation();
        $this->credit();
        $this->payment();
        $owner = TestEnvironment::pdo(owner: true);
        foreach (['amount_due_minor=1001', "currency='USD'", "basis_ref='new'", "terms_digest='".self::digest('b')."'", "billing_account_ref='other'"] as $change) {
            self::assertSame('55000', self::sqlState($owner, 'UPDATE billium_obligation SET '.$change.',revision=revision+1'));
        }
        foreach (['allocated_minor=1001', 'credited_minor=1001', 'adjusted_minor=-1001'] as $change) {
            self::assertSame('23514', self::sqlState($owner, 'UPDATE billium_obligation SET '.$change.',revision=revision+1'));
        }
        self::assertSame('22003', self::sqlState($owner, 'UPDATE billium_obligation SET adjusted_minor=9223372036854775807,revision=revision+1'));
        self::assertSame('23514', self::sqlState($owner, 'UPDATE billium_customer_credit SET remaining_minor=1001,revision=revision+1'));
        self::assertSame('55000', self::sqlState($owner, "UPDATE billium_customer_credit SET currency='USD'"));
        self::assertSame('23514', self::sqlState($owner, 'UPDATE billium_payment SET refunded_minor=1001'));
        self::assertSame('23514', self::sqlState($owner, 'UPDATE billium_payment SET allocated_minor=1001,reallocation_required=false'));
        self::assertSame('55000', self::sqlState($owner, "UPDATE billium_payment SET billing_account_ref='other'"));
        self::assertSame('55000', self::sqlState($owner, 'DELETE FROM billium_obligation'));
    }

    public function test_invoice_and_line_freeze_and_sanitized_metadata_are_backstops(): void
    {
        $o = $this->obligation();
        $invoice = $this->invoice($o, 'draft');
        $this->owner()->insert("INSERT INTO billium_invoice_line(installation_ref,seller_ref,environment,billing_account_ref,currency,invoice_id,obligation_id,position,subtotal_minor,tax_minor,amount_minor) VALUES('inst-test','seller-a','test','account-a','RUB',?,?,0,1000,0,1000)", [$invoice, $o]);
        $this->owner()->update("UPDATE billium_invoice SET status='issued',issued_at=now()");
        $owner = TestEnvironment::pdo(owner: true);
        foreach (['subtotal_minor=1100,total_minor=1100', "currency='USD'", "snapshot='{\"changed\":true}'", "issued_at=now() + interval '1 day'", "legal_document_ref='replacement'"] as $change) {
            self::assertSame('55000', self::sqlState($owner, 'UPDATE billium_invoice SET '.$change));
        }
        self::assertSame('55000', self::sqlState($owner, 'UPDATE billium_invoice_line SET amount_minor=999'));
        self::assertSame('55000', self::sqlState($owner, 'DELETE FROM billium_invoice_line'));
        self::assertSame('55000', self::sqlState($owner, "INSERT INTO billium_invoice_line(installation_ref,seller_ref,environment,billing_account_ref,currency,invoice_id,obligation_id,position,subtotal_minor,tax_minor,amount_minor) VALUES('inst-test','seller-a','test','account-a','RUB',1,1,1,0,0,0)"));
        self::assertSame('P0001', self::sqlState($owner, "UPDATE billium_invoice SET status='draft'"));
        self::assertSame('23514', self::sqlState($owner, "UPDATE billium_invoice SET metadata='{\"amount\":1}'"));
        self::assertSame('23514', self::sqlState($owner, "UPDATE billium_invoice SET metadata='{\"labels\":{\"token\":\"secret\"}}'"));
        self::assertSame('23514', self::sqlState($owner, "UPDATE billium_invoice SET metadata=jsonb_build_object('note',repeat('x',65537))"));
        self::assertNull(self::sqlState($owner, "UPDATE billium_invoice SET metadata='{\"note\":\"ok\",\"labels\":[]}'"));
        self::assertSame('23514', self::sqlState($owner, "UPDATE billium_invoice SET metadata='{\"labels\":{\"apiKey\":\"sk_live\"}}'"));
        self::assertNull(self::sqlState($owner, "UPDATE billium_invoice SET metadata='{\"labels\":{\"secret_ref\":\"secret://merchant/key\"}}'"));
        self::assertSame('P0001', self::sqlState($owner, 'UPDATE billium_obligation SET adjusted_minor=1,revision=revision+1'));
    }

    public function test_observed_actual_overcapture_and_overrefund_are_retained_but_applied_projection_is_bounded(): void
    {
        $this->owner()->insert(<<<'SQL'
            INSERT INTO billium_observed_payment(installation_ref,seller_ref,environment,source_authority,source_lineage,operation_ref,kind,revision,material_digest,state,currency,requested_minor,authorized_minor,captured_minor,refunded_minor,voided_minor,provider_account_ref,evidence_ref)
            VALUES('inst-test','seller-a','test','external','lineage','charge','charge',1,?,'succeeded','RUB',100,100,200,300,0,'merchant','evidence')
            SQL, [self::digest()]);
        self::assertSame(300, self::integer($this->value('SELECT refunded_minor FROM billium_observed_payment')));
        self::assertNull($this->value('SELECT billing_account_ref FROM billium_observed_payment'));
        self::assertSame(0, $this->rows('billium_payment'));
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(owner: true), "UPDATE billium_observed_payment SET currency='USD',revision=revision+1"));
        self::assertSame('23514', self::sqlState(TestEnvironment::pdo(owner: true), "UPDATE billium_observed_payment SET revision=revision+1,provider_metadata='{\"secret\":\"x\"}'"));
    }

    public function test_schema_owner_cannot_be_configured_as_the_runtime_role(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('separate runtime role');
        new LedgerPrivileges('billium_owner')->preflight();
    }

    public function test_preflight_rejects_global_write_role_membership(): void
    {
        $owner = $this->owner();
        $runtime = self::text(self::pdoValue(TestEnvironment::pdo(), 'SELECT current_user'));
        $role = '"'.str_replace('"', '""', $runtime).'"';
        /** @var Repository $config */
        $config = $this->application()['config'];
        $config->set('database.default', 'billium_owner');
        $owner->beginTransaction();
        try {
            // @phpstan-ignore argument.type (quoted database role and fixed test privilege)
            $owner->unprepared('ALTER ROLE '.$role.' INHERIT');
            // @phpstan-ignore argument.type (quoted database role and fixed test privilege)
            $owner->unprepared('GRANT pg_write_all_data TO '.$role);
            self::assertTrue($this->value("SELECT has_table_privilege(?, 'public.billium_customer_credit', 'UPDATE')", [$runtime]));
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('separate runtime role');
            new LedgerPrivileges('pgsql')->preflight();
        } finally {
            $owner->rollBack();
        }
    }

    /** @return array<string, array{string}> */
    public static function unsafeWriteGrants(): array
    {
        return [
            'inherited table grant' => ['UPDATE'],
            'inherited column grant' => ['UPDATE (remaining_minor)'],
        ];
    }

    #[DataProvider('unsafeWriteGrants')]
    public function test_grant_rejects_effective_writes_remaining_after_revocation(string $privilege): void
    {
        $owner = $this->owner();
        $runtime = self::text(self::pdoValue(TestEnvironment::pdo(), 'SELECT current_user'));
        $role = '"'.str_replace('"', '""', $runtime).'"';
        /** @var Repository $config */
        $config = $this->application()['config'];
        $config->set('database.default', 'billium_owner');
        $owner->beginTransaction();
        try {
            $owner->unprepared('CREATE ROLE billium_test_ledger_writer NOLOGIN');
            // @phpstan-ignore argument.type (quoted database role and fixed test privilege)
            $owner->unprepared('ALTER ROLE '.$role.' INHERIT');
            // @phpstan-ignore argument.type (quoted database role and fixed test privilege)
            $owner->unprepared('GRANT billium_test_ledger_writer TO '.$role);
            // @phpstan-ignore argument.type (quoted database role and fixed test privilege)
            $owner->unprepared('GRANT '.$privilege.' ON public.billium_customer_credit TO billium_test_ledger_writer');
            self::assertTrue($this->value("SELECT has_column_privilege(?, 'public.billium_customer_credit', 'remaining_minor', 'UPDATE')", [$runtime]));
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('effective write privileges on protected ledger table billium_customer_credit');
            new LedgerPrivileges('pgsql')->grant();
        } finally {
            $owner->rollBack();
        }
    }

    public function test_grant_removes_direct_and_public_table_and_column_writes(): void
    {
        $owner = $this->owner();
        $runtime = self::text(self::pdoValue(TestEnvironment::pdo(), 'SELECT current_user'));
        $role = '"'.str_replace('"', '""', $runtime).'"';
        /** @var Repository $config */
        $config = $this->application()['config'];
        $config->set('database.default', 'billium_owner');
        $owner->beginTransaction();
        try {
            // @phpstan-ignore argument.type (quoted database role and fixed test privilege)
            $owner->unprepared('GRANT INSERT, UPDATE (remaining_minor) ON public.billium_customer_credit TO PUBLIC, '.$role);
            new LedgerPrivileges('pgsql')->grant();
            self::assertFalse($this->value("SELECT has_table_privilege(?, 'public.billium_customer_credit', 'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER') OR has_any_column_privilege(?, 'public.billium_customer_credit', 'INSERT,UPDATE,REFERENCES')", [$runtime, $runtime]));
            self::assertTrue($this->value("SELECT has_table_privilege(?, 'public.billium_customer_credit', 'SELECT')", [$runtime]));
        } finally {
            $owner->rollBack();
        }
    }

    public function test_adjustment_and_credit_note_journals_enforce_open_budget_with_raw_sql(): void
    {
        $o = $this->obligation();
        $credit = $this->credit();
        $this->allocate($o, null, $credit, 800);
        $owner = TestEnvironment::pdo(owner: true);
        $adjust = "INSERT INTO billium_adjustment(installation_ref,seller_ref,environment,billing_account_ref,currency,adjustment_ref,obligation_id,kind,amount_minor,cause_ref,revision,semantic_digest,receipt_ref) VALUES('inst-test','seller-a','test','account-a','RUB','raw',1,'decrease',201,'correction',3,'".self::digest()."','obligation:basis-obligation-a')";
        self::assertSame('P0001', self::sqlState($owner, $adjust));
        $note = "INSERT INTO billium_credit_note(installation_ref,seller_ref,environment,billing_account_ref,currency,credit_note_ref,obligation_id,amount_minor,reason,mode,receipt_ref) VALUES('inst-test','seller-a','test','account-a','RUB','note',1,201,'credit','open_only','obligation:basis-obligation-a')";
        self::assertSame('P0001', self::sqlState($owner, $note));
        self::assertSame(0, $this->rows('billium_credit_note'));
        self::assertSame(0, $this->rows('billium_adjustment'));
        self::assertSame(800, self::integer($this->value('SELECT allocated_minor FROM billium_obligation')));
    }

    public function test_journals_update_invoice_and_obligation_balances_and_derived_states_once(): void
    {
        $o = $this->obligation();
        $this->invoice($o);
        $credit = $this->credit();
        $this->allocate($o, null, $credit, 600);
        self::assertSame(400, self::integer($this->value('SELECT open_minor FROM billium_invoice')));
        self::assertSame('partially_paid', $this->value('SELECT status FROM billium_invoice'));
        $this->owner()->insert("INSERT INTO billium_credit_note(installation_ref,seller_ref,environment,billing_account_ref,currency,credit_note_ref,invoice_id,obligation_id,amount_minor,reason,mode,receipt_ref) VALUES('inst-test','seller-a','test','account-a','RUB','note',1,1,400,'credit','open_only','obligation:basis-obligation-a')");
        self::assertSame(0, self::integer($this->value('SELECT open_minor FROM billium_invoice')));
        self::assertSame(0, self::integer($this->value('SELECT open_minor FROM billium_obligation')));
        self::assertSame('paid', $this->value('SELECT status FROM billium_invoice'));
        self::assertSame('settled', $this->value('SELECT status FROM billium_obligation'));
        $this->allocate($o, null, $credit, -600, 'reverse', 1);
        self::assertSame(600, self::integer($this->value('SELECT open_minor FROM billium_invoice')));
        self::assertSame(600, self::integer($this->value('SELECT open_minor FROM billium_obligation')));
        self::assertSame('partially_paid', $this->value('SELECT status FROM billium_invoice'));
        self::assertSame('open', $this->value('SELECT status FROM billium_obligation'));
        self::assertSame(1000, self::integer($this->value('SELECT remaining_minor FROM billium_customer_credit')));
    }

    public function test_allocation_reversal_keeps_a_cancelled_obligation_cancelled(): void
    {
        $o = $this->obligation();
        $credit = $this->credit();
        $this->allocate($o, null, $credit, 600);
        $this->owner()->insert("UPDATE billium_obligation SET status='cancelled',revision=revision+1");
        $this->allocate($o, null, $credit, -600, 'reverse', 1);
        self::assertSame(0, self::integer($this->value('SELECT allocated_minor FROM billium_obligation')));
        self::assertSame('cancelled', $this->value('SELECT status FROM billium_obligation'));
    }
}
