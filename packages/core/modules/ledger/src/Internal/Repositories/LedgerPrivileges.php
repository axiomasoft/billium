<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Repositories;

use Illuminate\Support\Facades\DB;
use LogicException;
use Packman\Attributes\Internal;

/** D60: installation fails closed without a separate, unprivileged runtime role. Owner/DBA is outside the backstop. */
#[Internal]
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
        if (($data['present'] ?? false) !== true || ($data['same'] ?? true) === true || ($data['privileged'] ?? true) === true
            || ($data['schema_owner'] ?? true) === true || ($data['owner_member'] ?? true) === true || ($data['can_create'] ?? true) === true) {
            throw new LogicException('D60: ledger requires a separate runtime role without schema-owner powers');
        }
    }

    /** Protected ledger tables: the runtime reads them and writes only through the guarded routines. */
    private const array TABLES = ['obligation', 'adjustment', 'customer_credit', 'settlement_intent', 'settlement_target', 'settlement_intent_closure',
        'invoice', 'invoice_line', 'observed_payment', 'observed_payment_revision', 'payment', 'payment_revision', 'allocation',
        'allocation_receipt', 'credit_note', 'ledger_discrepancy', 'discrepancy_event',
        'observation_park', 'refund_reconcile_progress', 'observation_owner_binding', 'observed_revision_owner_proof'];

    /** Guarded routines the runtime may execute. InvoiceCommandsTest asserts their runtime EXECUTE grants. */
    private const array ROUTINES = ['billium_record_obligation', 'billium_adjust_obligation', 'billium_record_customer_credit',
        'billium_declare_settlement_intent', 'billium_close_settlement_intent_no_effect', 'billium_allocate',
        'billium_lock_obligation', 'billium_lock_invoice', 'billium_lock_payment', 'billium_lock_customer_credit', 'billium_lock_settlement_intent',
        'billium_create_invoice', 'billium_update_invoice_metadata', 'billium_issue_invoice', 'billium_close_invoice', 'billium_mark_invoice_uncollectible',
        'billium_reverse_allocation', 'billium_issue_credit_note', 'billium_reallocate_payment',
        'billium_prove_observation_revision', 'billium_claim_observation_owner', 'billium_record_observation', 'billium_project_payment',
        'billium_record_refund_detail', 'billium_park_observation', 'billium_release_observation_park', 'billium_reconcile_refund_basis',
        'billium_amend_obligation_due_policy'];

    /** Private helpers and guards. Revoked explicitly to override unsafe deployment defaults: never a runtime capability. */
    private const array PRIVATE_ROUTINES = ['billium_ledger_json_safe', 'billium_invoice_metadata_allowlist', 'billium_ledger_receipt', 'billium_observed_payment_guard',
        'billium_obligation_terms_immutable', 'billium_invoice_freeze', 'billium_invoice_line_freeze',
        'billium_settlement_intent_guard', 'billium_allocation_guard', 'billium_funds_identity_guard',
        'billium_credit_note_guard', 'billium_adjustment_guard', 'billium_payment_intent_guard',
        'billium_invoice_replay', 'billium_ledger_lock_scope', 'billium_ledger_release', 'billium_observation_park_guard'];

    /** Runs once the stream has created every protected table and routine. */
    public function grant(): void
    {
        $this->preflight();
        $runtimeRole = $this->role();
        $role = '"'.str_replace('"', '""', $runtimeRole).'"';
        $pdo = DB::connection()->getPdo();
        foreach (self::TABLES as $table) {
            $pdo->exec('REVOKE ALL ON TABLE public.billium_'.$table.' FROM PUBLIC, '.$role);
            $pdo->exec('GRANT SELECT ON TABLE public.billium_'.$table.' TO '.$role);
        }
        // REVOKE cannot remove privileges inherited from other roles. Check table and column capabilities.
        $writable = DB::select(<<<'SQL'
            SELECT t AS name FROM unnest(?::text[]) AS t
            WHERE has_table_privilege(?, ('public.billium_' || t)::regclass, 'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')
               OR has_any_column_privilege(?, ('public.billium_' || t)::regclass, 'INSERT,UPDATE,REFERENCES')
            ORDER BY t
            SQL, [self::textArray(self::TABLES), $runtimeRole, $runtimeRole]);
        foreach ($writable as $row) {
            throw new LogicException('D60: runtime has effective write privileges on protected ledger table billium_'.self::text($row, 'name'));
        }
        foreach ($this->signatures(self::PRIVATE_ROUTINES, 'Missing ledger guard ', false) as $signature) {
            $pdo->exec('REVOKE ALL ON FUNCTION '.$signature.' FROM '.$role);
        }
        foreach ($this->signatures(self::ROUTINES, 'Missing ledger routine ', true) as $signature) {
            $pdo->exec('GRANT EXECUTE ON FUNCTION '.$signature.' TO '.$role);
        }
    }

    /**
     * One catalog read for every routine name, in the order given.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function signatures(array $names, string $missing, bool $ownedByCurrentUser): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT p.proname AS name, p.oid::regprocedure::text AS signature FROM pg_proc p
            WHERE p.pronamespace = 'public'::regnamespace AND p.proname = ANY(?::text[])
              AND (NOT ?::boolean OR p.proowner = (SELECT oid FROM pg_roles WHERE rolname = current_user))
            ORDER BY p.oid
            SQL, [self::textArray($names), $ownedByCurrentUser ? 'true' : 'false']);
        $found = [];
        foreach ($rows as $row) {
            $found[self::text($row, 'name')] ??= self::text($row, 'signature');
        }

        return array_map(static fn (string $name): string => $found[$name] ?? throw new LogicException($missing.$name), $names);
    }

    /** @param list<string> $values */
    private static function textArray(array $values): string
    {
        return '{'.implode(',', $values).'}';
    }

    private static function text(mixed $row, string $column): string
    {
        $value = is_object($row) ? (get_object_vars($row)[$column] ?? null) : null;

        return is_string($value) ? $value : throw new LogicException('Unexpected catalog row');
    }

    private function role(): string
    {
        $role = DB::connection($this->runtimeConnection)->getConfig('username');

        return is_string($role) && $role !== '' ? $role : throw new LogicException('Ledger runtime role is required');
    }
}
