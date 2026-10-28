<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // D55: append-only lifecycle history; a transition never deletes facts or impersonates PSP cancellation.
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_module_transitions (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                transition_ref text NOT NULL CHECK (transition_ref <> '' AND length(transition_ref) <= 200),
                installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
                module_id text NOT NULL CHECK (module_id ~ '^[a-z][a-z0-9_]*$'),
                from_state text NULL CHECK (from_state IN ('accepting', 'draining', 'recovery_only', 'retired')),
                to_state text NOT NULL CHECK (to_state IN ('accepting', 'draining', 'recovery_only', 'retired')),
                from_generation bigint NULL CHECK (from_generation >= 1),
                to_generation bigint NOT NULL CHECK (to_generation >= 1),
                actor_ref text NOT NULL CHECK (actor_ref <> '' AND length(actor_ref) <= 200),
                evidence_ref text NULL CHECK (evidence_ref IS NULL OR (evidence_ref <> '' AND length(evidence_ref) <= 200)),
                reason text NOT NULL CHECK (reason <> '' AND length(reason) <= 1000),
                occurred_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT billium_module_transitions_ref_key UNIQUE (transition_ref),
                CONSTRAINT billium_module_transitions_generation CHECK (
                    (from_generation IS NULL AND from_state IS NULL AND to_generation = 1)
                    OR to_generation = from_generation + 1
                )
            );
            CREATE INDEX billium_module_transitions_module ON public.billium_module_transitions (installation_ref, module_id, to_generation);
            CREATE TRIGGER billium_module_transitions_append_only BEFORE UPDATE OR DELETE ON public.billium_module_transitions
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            SQL);
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_module_transitions');
    }

    public function down(): void
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
