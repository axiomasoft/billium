<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        // D55/CV-R11: admission holds these rows FOR SHARE (rank 25), a transition FOR UPDATE; every change bumps generation.
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_module_state (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
                module_id text NOT NULL CHECK (module_id ~ '^[a-z][a-z0-9_]*$' AND length(module_id) <= 64),
                state text NOT NULL CHECK (state IN ('accepting', 'draining', 'recovery_only', 'retired')),
                config_revision bigint NOT NULL CHECK (config_revision >= 1),
                schema_version integer NOT NULL CHECK (schema_version >= 0),
                generation bigint NOT NULL CHECK (generation >= 1),
                last_transition_ref text NULL CHECK (last_transition_ref IS NULL OR (last_transition_ref <> '' AND length(last_transition_ref) <= 200)),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT billium_module_state_module_key UNIQUE (installation_ref, module_id)
            );

            CREATE OR REPLACE FUNCTION public.billium_module_state_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium: module state is never deleted (D55)' USING ERRCODE = '55000';
                END IF;
                IF (NEW.id, NEW.installation_ref, NEW.module_id) IS DISTINCT FROM (OLD.id, OLD.installation_ref, OLD.module_id)
                   OR NEW.generation <> OLD.generation + 1
                   OR NEW.last_transition_ref IS NULL
                   OR NEW.last_transition_ref IS NOT DISTINCT FROM OLD.last_transition_ref THEN
                    RAISE EXCEPTION 'billium: a module change is one transition with the next generation' USING ERRCODE = '55000';
                END IF;
                IF NEW.state IS DISTINCT FROM OLD.state AND NOT (
                       (OLD.state = 'accepting' AND NEW.state = 'draining')
                    OR (OLD.state = 'draining' AND NEW.state IN ('recovery_only', 'accepting'))
                    OR (OLD.state = 'recovery_only' AND NEW.state = 'retired')
                ) THEN
                    RAISE EXCEPTION 'billium: module % -> % is not a lifecycle step', OLD.state, NEW.state USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_module_state_guard() FROM PUBLIC;
            CREATE TRIGGER billium_module_state_guard BEFORE UPDATE OR DELETE ON public.billium_module_state
                FOR EACH ROW EXECUTE FUNCTION public.billium_module_state_guard();
            SQL);
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_module_state');
        RuntimeGrants::grant('UPDATE (state, config_revision, schema_version, generation, last_transition_ref, updated_at)', 'public.billium_module_state');
    }

    public function down(): void
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
