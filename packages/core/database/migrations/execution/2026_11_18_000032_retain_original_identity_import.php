<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_execution_original_identity_import (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK (length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK (length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK (length(environment) BETWEEN 1 AND 200),
                intent_owner_namespace text NOT NULL CHECK (length(intent_owner_namespace) BETWEEN 1 AND 200),
                operation_ref text NOT NULL CHECK (length(operation_ref) BETWEEN 1 AND 200),
                attempt_ref text NOT NULL CHECK (length(attempt_ref) BETWEEN 1 AND 200),
                command_key_digest text NOT NULL CHECK (command_key_digest ~ '^sha256:[0-9a-f]{64}$'),
                input_digest text NOT NULL CHECK (input_digest ~ '^sha256:[0-9a-f]{64}$'),
                manifest_digest text NOT NULL CHECK (manifest_digest ~ '^sha256:[0-9a-f]{64}$'),
                source_journal_digest text NOT NULL CHECK (source_journal_digest ~ '^sha256:[0-9a-f]{64}$'),
                identity_digest text NOT NULL CHECK (identity_digest ~ '^sha256:[0-9a-f]{64}$'),
                original_lineage text NOT NULL CHECK (length(original_lineage) BETWEEN 1 AND 200),
                original_fence bigint NOT NULL CHECK (original_fence>0),
                adopted_epoch bigint NOT NULL CHECK (adopted_epoch>0),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (installation_ref,seller_ref,environment,operation_ref),
                UNIQUE (installation_ref,seller_ref,environment,attempt_ref),
                UNIQUE (installation_ref,seller_ref,environment,intent_owner_namespace,command_key_digest),
                FOREIGN KEY (installation_ref,seller_ref,environment,attempt_ref)
                    REFERENCES public.billium_execution_attempt(installation_ref,seller_ref,environment,attempt_ref),
                FOREIGN KEY (installation_ref,seller_ref,environment,operation_ref)
                    REFERENCES public.billium_execution_operation(installation_ref,seller_ref,environment,operation_ref)
            );
            CREATE TRIGGER billium_execution_original_identity_import_immutable BEFORE UPDATE OR DELETE
                ON public.billium_execution_original_identity_import FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();
            SQL);
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_original_identity_import');
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
