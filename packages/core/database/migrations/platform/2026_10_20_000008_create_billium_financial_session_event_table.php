<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Immutable history of session transitions with their external admission/evidence references (D54).
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_financial_session_event (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                session_id bigint NOT NULL REFERENCES public.billium_financial_session (id),
                transition_ref text NOT NULL CHECK (transition_ref <> '' AND length(transition_ref) <= 200),
                transition text NOT NULL CHECK (transition IN ('opened', 'activated', 'sealing', 'sealed', 'superseded')),
                external_admission_ref text NULL CHECK (external_admission_ref IS NULL OR length(external_admission_ref) <= 200),
                evidence_ref text NULL CHECK (evidence_ref IS NULL OR length(evidence_ref) <= 200),
                actor_ref text NOT NULL CHECK (actor_ref <> '' AND length(actor_ref) <= 200),
                occurred_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT billium_financial_session_event_transition_key UNIQUE (session_id, transition_ref)
            );
            CREATE TRIGGER billium_financial_session_event_append_only BEFORE UPDATE OR DELETE ON public.billium_financial_session_event
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            SQL);
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_financial_session_event');
    }

    public function down(): void
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
