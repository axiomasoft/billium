<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        // D58: a host provisions journal privileges separately from the ledger runtime role.
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_authority_route (
                scope_key text PRIMARY KEY CHECK (scope_key ~ '^sha256:[0-9a-f]{64}$'),
                executor_ref text NOT NULL CHECK (length(executor_ref) BETWEEN 1 AND 200),
                previous_executor_ref text CHECK (length(previous_executor_ref) BETWEEN 1 AND 200),
                routing_epoch bigint NOT NULL CHECK (routing_epoch BETWEEN 0 AND 9007199254740991)
            );
            CREATE TABLE public.billium_intent_binding (
                scope_key text NOT NULL REFERENCES public.billium_authority_route (scope_key),
                kind text NOT NULL CHECK (kind IN ('charge', 'refund')),
                source_context text NOT NULL CHECK (length(source_context) BETWEEN 1 AND 200),
                business_intent_ref text NOT NULL CHECK (length(business_intent_ref) BETWEEN 1 AND 200),
                operation_ref text NOT NULL CHECK (length(operation_ref) BETWEEN 1 AND 200),
                executor_ref text NOT NULL CHECK (length(executor_ref) BETWEEN 1 AND 200),
                routing_epoch bigint NOT NULL CHECK (routing_epoch BETWEEN 0 AND 9007199254740991),
                merchant_account_ref text NOT NULL CHECK (length(merchant_account_ref) BETWEEN 1 AND 200),
                binding_revision text NOT NULL CHECK (length(binding_revision) BETWEEN 1 AND 200),
                command_key text NOT NULL CHECK (length(command_key) BETWEEN 1 AND 200),
                payload_digest text NOT NULL CHECK (payload_digest ~ '^sha256:[0-9a-f]{64}$'),
                original_charge_ref text,
                PRIMARY KEY (scope_key, operation_ref),
                UNIQUE (scope_key, source_context, business_intent_ref, kind),
                UNIQUE (scope_key, command_key),
                FOREIGN KEY (scope_key, original_charge_ref) REFERENCES public.billium_intent_binding (scope_key, operation_ref),
                CHECK ((kind = 'charge') = (original_charge_ref IS NULL) AND original_charge_ref IS DISTINCT FROM operation_ref)
            );
            CREATE TRIGGER billium_intent_binding_append_only BEFORE UPDATE OR DELETE ON public.billium_intent_binding
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('forward-only (D21, REQ-FAIL-29)');
    }
};
