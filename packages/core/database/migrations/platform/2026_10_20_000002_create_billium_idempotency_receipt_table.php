<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // INV-05/D51: the claim is the first write of the root Tx; claim and complete commit together.
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_idempotency_receipt (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                environment text NOT NULL CHECK (environment <> '' AND length(environment) <= 200),
                seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
                command_kind text NOT NULL CHECK (command_kind ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$'),
                command_key text NOT NULL CHECK (command_key <> '' AND length(command_key) <= 200),
                digest text NOT NULL CHECK (digest <> '' AND length(digest) <= 200),
                canonicalization_version text NOT NULL DEFAULT 'jcs-rfc8785+sha256'
                    CHECK (canonicalization_version = 'jcs-rfc8785+sha256'),
                outcome text NOT NULL CHECK (outcome IN ('in_progress', 'accepted', 'refused')),
                result jsonb NULL CHECK (result IS NULL OR (jsonb_typeof(result) = 'object' AND octet_length(result::text) <= 65536)),
                created_at timestamptz NOT NULL DEFAULT now(),
                completed_at timestamptz NULL,
                expires_at timestamptz NULL,
                CONSTRAINT billium_idempotency_receipt_scope_key UNIQUE (environment, seller_ref, command_kind, command_key),
                CONSTRAINT billium_idempotency_receipt_completed CHECK (
                    (outcome = 'in_progress') = (result IS NULL) AND (outcome = 'in_progress') = (completed_at IS NULL)
                )
            );

            CREATE FUNCTION public.billium_idempotency_receipt_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium: idempotency receipts are never deleted by runtime' USING ERRCODE = '55000';
                END IF;
                IF OLD.outcome <> 'in_progress' OR NEW.outcome = 'in_progress'
                   OR (NEW.id, NEW.environment, NEW.seller_ref, NEW.command_kind, NEW.command_key, NEW.digest,
                       NEW.canonicalization_version, NEW.created_at, NEW.expires_at)
                      IS DISTINCT FROM
                      (OLD.id, OLD.environment, OLD.seller_ref, OLD.command_kind, OLD.command_key, OLD.digest,
                       OLD.canonicalization_version, OLD.created_at, OLD.expires_at) THEN
                    RAISE EXCEPTION 'billium: a completed receipt and its identity are immutable' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_idempotency_receipt_guard() FROM PUBLIC;
            CREATE TRIGGER billium_idempotency_receipt_guard BEFORE UPDATE OR DELETE ON public.billium_idempotency_receipt
                FOR EACH ROW EXECUTE FUNCTION public.billium_idempotency_receipt_guard();
            SQL);
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_idempotency_receipt');
        RuntimeGrants::grant('UPDATE (outcome, result, completed_at)', 'public.billium_idempotency_receipt');
    }

    public function down(): void
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
