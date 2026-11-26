<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_execution_original_replay (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK (length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK (length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK (length(environment) BETWEEN 1 AND 200),
                intent_owner_namespace text NOT NULL CHECK (length(intent_owner_namespace) BETWEEN 1 AND 200),
                approval_ref text NOT NULL CHECK (length(approval_ref) BETWEEN 1 AND 200),
                attempt_ref text NOT NULL CHECK (length(attempt_ref) BETWEEN 1 AND 200),
                operation_ref text NOT NULL CHECK (length(operation_ref) BETWEEN 1 AND 200),
                writer_ticket_ref text NOT NULL CHECK (length(writer_ticket_ref) BETWEEN 1 AND 200),
                original_request_digest text NOT NULL CHECK (original_request_digest ~ '^sha256:[0-9a-f]{64}$'),
                original_started_at timestamptz NOT NULL,
                window_ends_at timestamptz NOT NULL,
                deadline timestamptz NOT NULL,
                lease_fence bigint NOT NULL CHECK (lease_fence>0),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CHECK (original_started_at<deadline AND deadline<=window_ends_at),
                UNIQUE (installation_ref,seller_ref,environment,intent_owner_namespace,approval_ref),
                UNIQUE (installation_ref,seller_ref,environment,intent_owner_namespace,writer_ticket_ref),
                FOREIGN KEY (installation_ref,seller_ref,environment,attempt_ref)
                    REFERENCES public.billium_execution_attempt(installation_ref,seller_ref,environment,attempt_ref),
                FOREIGN KEY (installation_ref,seller_ref,environment,operation_ref)
                    REFERENCES public.billium_execution_operation(installation_ref,seller_ref,environment,operation_ref)
            );
            CREATE TRIGGER billium_execution_original_replay_immutable BEFORE UPDATE OR DELETE
                ON public.billium_execution_original_replay FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();
            CREATE INDEX billium_execution_original_replay_attempt
                ON public.billium_execution_original_replay(installation_ref,seller_ref,environment,attempt_ref,id);
            CREATE INDEX billium_execution_recovery_queue_clock
                ON public.billium_execution_dispatch_work(installation_ref,seller_ref,environment,
                    (CASE WHEN state='leased' THEN lease_expires_at ELSE due_at END),id)
                WHERE state IN ('due','leased');
            SQL);
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_original_replay');
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
