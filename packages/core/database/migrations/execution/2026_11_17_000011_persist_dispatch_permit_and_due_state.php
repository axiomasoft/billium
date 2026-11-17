<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.billium_execution_attempt ADD COLUMN collection_dispatch_permit_ref text;
            ALTER TABLE public.billium_execution_attempt ADD CONSTRAINT ex_collection_dispatch_permit_length
                CHECK (length(collection_dispatch_permit_ref) BETWEEN 1 AND 200) NOT VALID;
            DROP TRIGGER billium_execution_attempt_immutable ON public.billium_execution_attempt;
            CREATE TRIGGER billium_execution_attempt_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_attempt
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('state','lease_owner','lease_fence','lease_expires_at',
                    'session_epoch','owner_session_ref','dispatch_ticket_ref','started_at','request_deadline_at','finish_evidence_ref','collection_dispatch_permit_ref');

            ALTER TABLE public.billium_execution_dispatch_work ALTER COLUMN due_at DROP NOT NULL;
            UPDATE public.billium_execution_dispatch_work SET due_at=NULL WHERE state IN ('processed','parked');
            ALTER TABLE public.billium_execution_dispatch_work ADD CONSTRAINT ex_dispatch_due_state
                CHECK ((state IN ('processed','parked')) = (due_at IS NULL) AND (due_at IS NULL OR isfinite(due_at))) NOT VALID;
            CREATE UNIQUE INDEX billium_execution_dispatch_one_work
                ON public.billium_execution_dispatch_work(installation_ref,seller_ref,environment,attempt_ref);
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
