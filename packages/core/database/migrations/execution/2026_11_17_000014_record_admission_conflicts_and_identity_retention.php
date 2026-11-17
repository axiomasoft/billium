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
            CREATE TABLE public.billium_execution_admission_conflict (
                audit_ref text PRIMARY KEY CHECK (audit_ref ~ '^conflict:[a-f0-9]{64}$'),
                installation_ref text NOT NULL CHECK (length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK (length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK (length(environment) BETWEEN 1 AND 200),
                intent_owner_namespace text NOT NULL CHECK (length(intent_owner_namespace) BETWEEN 1 AND 200),
                actor_ref text NOT NULL CHECK (length(actor_ref) BETWEEN 1 AND 200),
                command_kind text NOT NULL CHECK (command_kind IN ('payment.execute.v1','payment.authorize.v1')),
                command_key_digest text NOT NULL CHECK (command_key_digest ~ '^sha256:[a-f0-9]{64}$'),
                semantic_digest text NOT NULL CHECK (semantic_digest ~ '^sha256:[a-f0-9]{64}$'),
                original_operation_ref text CHECK (length(original_operation_ref) BETWEEN 1 AND 200),
                problem_code text NOT NULL CHECK (problem_code = 'billium.idempotency.conflict'),
                recorded_at timestamptz NOT NULL DEFAULT clock_timestamp()
            );
            CREATE TRIGGER billium_execution_admission_conflict_immutable BEFORE UPDATE OR DELETE
                ON public.billium_execution_admission_conflict FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();

            ALTER TABLE public.billium_execution_business_binding ADD COLUMN retention_policy text NOT NULL
                DEFAULT 'execution-business-identity-indefinite-v1';
            ALTER TABLE public.billium_execution_business_binding ADD COLUMN retention_deadline_at timestamptz;
            ALTER TABLE public.billium_execution_business_binding ADD CONSTRAINT ex_business_identity_retention
                CHECK (retention_policy = 'execution-business-identity-indefinite-v1' AND retention_deadline_at IS NULL) NOT VALID;
            SQL);
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_admission_conflict');
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
