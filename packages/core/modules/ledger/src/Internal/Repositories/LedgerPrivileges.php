<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Repositories;

use Illuminate\Support\Facades\DB;
use LogicException;

/** D60: installation fails closed without a separate, unprivileged runtime role. Owner/DBA is outside the backstop. */
final readonly class LedgerPrivileges
{
    public function __construct(private string $runtimeConnection) {}

    public function preflight(): void
    {
        $runtimeRole = $this->role();
        $row = DB::selectOne(<<<'SQL'
            SELECT EXISTS (SELECT FROM pg_roles WHERE rolname = ?) AS present,
                current_user = ? AS same,
                EXISTS (SELECT FROM pg_roles WHERE pg_has_role(?,oid,'MEMBER') AND
                    (rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls OR rolname='pg_write_all_data')) AS privileged,
                EXISTS (SELECT FROM pg_namespace WHERE nspname='public' AND pg_has_role(?,nspowner,'MEMBER')) AS schema_owner,
                (EXISTS (SELECT FROM pg_roles WHERE rolname=current_user AND pg_has_role(?,oid,'MEMBER'))
                 OR EXISTS (SELECT FROM pg_database WHERE datname=current_database() AND pg_has_role(?,datdba,'MEMBER'))
                 OR EXISTS (SELECT FROM pg_class WHERE relnamespace='public'::regnamespace AND pg_has_role(?,relowner,'MEMBER'))) AS owner_member,
                has_schema_privilege(?, 'public', 'CREATE') AS can_create
            SQL, array_fill(0, 8, $runtimeRole));
        $data = is_object($row) ? get_object_vars($row) : [];
        if (($data['present'] ?? false) !== true || ($data['same'] ?? true) || ($data['privileged'] ?? true)
            || ($data['schema_owner'] ?? true) || ($data['owner_member'] ?? true) || ($data['can_create'] ?? true)) {
            throw new LogicException('D60: ledger requires a separate runtime role without schema-owner powers');
        }
    }

    public function grant(): void
    {
        $this->preflight();
        $role = '"'.str_replace('"', '""', $this->role()).'"';
        $tables = ['obligation', 'adjustment', 'customer_credit', 'settlement_intent', 'settlement_target', 'settlement_intent_closure',
            'invoice', 'invoice_line', 'observed_payment', 'observed_payment_revision', 'payment', 'payment_revision', 'allocation',
            'allocation_receipt', 'credit_note', 'ledger_discrepancy', 'discrepancy_event'];
        foreach ($tables as $table) {
            DB::unprepared('REVOKE ALL ON TABLE public.billium_'.$table.' FROM PUBLIC, '.$role);
            DB::unprepared('GRANT SELECT ON TABLE public.billium_'.$table.' TO '.$role);
            // REVOKE cannot remove privileges inherited from other roles. Check table and column capabilities.
            $runtimeRole = $this->role();
            $row = DB::selectOne(<<<'SQL'
                SELECT has_table_privilege(?, ?::regclass, 'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')
                    OR has_any_column_privilege(?, ?::regclass, 'INSERT,UPDATE,REFERENCES') AS can_write
                SQL, [$runtimeRole, 'public.billium_'.$table, $runtimeRole, 'public.billium_'.$table]);
            if (! is_object($row) || ($row->can_write ?? true) !== false) {
                throw new LogicException('D60: runtime has effective write privileges on protected ledger table billium_'.$table);
            }
        }
        $names = ['billium_record_obligation', 'billium_adjust_obligation', 'billium_record_customer_credit',
            'billium_declare_settlement_intent', 'billium_close_settlement_intent_no_effect', 'billium_allocate',
            'billium_lock_obligation', 'billium_lock_invoice', 'billium_lock_payment', 'billium_lock_customer_credit', 'billium_lock_settlement_intent'];
        // Override unsafe deployment defaults too: the private receipt writer is never a runtime capability.
        $private = ['billium_ledger_json_safe', 'billium_invoice_metadata_allowlist', 'billium_ledger_receipt', 'billium_observed_payment_guard',
            'billium_obligation_terms_immutable', 'billium_invoice_freeze', 'billium_invoice_line_freeze',
            'billium_settlement_intent_guard', 'billium_allocation_guard', 'billium_funds_identity_guard',
            'billium_credit_note_guard', 'billium_adjustment_guard', 'billium_payment_intent_guard'];
        // Routines of later migrations of the stream: earlier migrations run grant() before they exist and the migration
        // that creates them grants again. InvoiceCommandsTest asserts their runtime EXECUTE grants.
        $staged = ['billium_create_invoice', 'billium_update_invoice_metadata', 'billium_issue_invoice', 'billium_close_invoice', 'billium_mark_invoice_uncollectible',
            'billium_reverse_allocation', 'billium_issue_credit_note', 'billium_reallocate_payment'];
        $stagedPrivate = ['billium_invoice_replay', 'billium_ledger_lock_scope', 'billium_ledger_release'];
        $present = static fn (string $name): bool => DB::selectOne("SELECT 1 AS present FROM pg_proc WHERE pronamespace='public'::regnamespace AND proname=?", [$name]) !== null;
        $names = [...$names, ...array_filter($staged, $present)];
        $private = [...$private, ...array_filter($stagedPrivate, $present)];
        foreach ($private as $name) {
            $row = DB::selectOne("SELECT p.oid::regprocedure::text AS signature FROM pg_proc p WHERE p.pronamespace='public'::regnamespace AND p.proname=?", [$name]);
            $signature = is_object($row) && is_string($row->signature ?? null) ? $row->signature : throw new LogicException('Missing ledger guard '.$name);
            DB::unprepared('REVOKE ALL ON FUNCTION '.$signature.' FROM '.$role);
        }
        foreach ($names as $name) {
            $runtimeRole = $this->role();
            $row = DB::selectOne(<<<'SQL'
                SELECT p.oid::regprocedure::text AS signature FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                WHERE n.nspname='public' AND p.proname=? AND p.proowner=(SELECT oid FROM pg_roles WHERE rolname=current_user)
                SQL, [$name]);
            $signature = is_object($row) && is_string($row->signature ?? null) ? $row->signature : throw new LogicException('Missing ledger routine '.$name);
            DB::unprepared('GRANT EXECUTE ON FUNCTION '.$signature.' TO '.$role);
        }
    }

    private function role(): string
    {
        $role = DB::connection($this->runtimeConnection)->getConfig('username');

        return is_string($role) && $role !== '' ? $role : throw new LogicException('Ledger runtime role is required');
    }
}
