<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

/**
 * Collection work.
 */
return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_collection_work (
                step_id bigint PRIMARY KEY REFERENCES public.billium_collection_authorization(step_id),
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test', 'live')),
                status text NOT NULL DEFAULT 'due' CHECK (status IN ('due', 'leased', 'done', 'parked')),
                next_attempt_at timestamptz,
                fence bigint NOT NULL DEFAULT 0 CHECK (fence >= 0),
                delivery_claims integer NOT NULL DEFAULT 0 CHECK (delivery_claims >= 0),
                automatic_due boolean NOT NULL DEFAULT false,
                last_reason text,
                CHECK ((status IN ('due', 'leased')) = (next_attempt_at IS NOT NULL))
            );

            CREATE INDEX billium_collection_work_due_idx
                ON public.billium_collection_work (seller_ref, status, next_attempt_at, step_id)
                WHERE next_attempt_at IS NOT NULL;

            CREATE FUNCTION public.billium_lock_collection_dunning_case(
                p_ids bigint[], p_seller text, p_environment text, p_shared boolean
            ) RETURNS TABLE(lock_key bigint) LANGUAGE sql SET search_path = pg_catalog, pg_temp AS $$
                SELECT c.id FROM public.billium_collection_dunning_case c
                WHERE c.id = ANY(p_ids) AND c.seller_ref = p_seller AND c.environment = p_environment AND NOT p_shared
                ORDER BY c.id FOR UPDATE SKIP LOCKED
            $$;
            REVOKE ALL ON FUNCTION public.billium_lock_collection_dunning_case(p_ids bigint[], p_seller text, p_environment text, p_shared boolean) FROM PUBLIC;

            CREATE FUNCTION public.billium_collection_select_work(
                p_installation text, p_seller text, p_environment text, p_namespace text, p_profile text,
                p_recovery boolean, p_parked_step bigint, p_limit integer
            ) RETURNS TABLE(lock_key bigint) LANGUAGE sql SET search_path = pg_catalog, pg_temp AS $$
                SELECT w.step_id FROM public.billium_collection_work w
                JOIN public.billium_collection_authorization a USING (step_id, seller_ref, environment)
                WHERE w.seller_ref = p_seller AND w.environment = p_environment AND a.installation_ref = p_installation
                  AND a.intent_owner_namespace = p_namespace AND a.execution_profile = p_profile
                  AND (NOT p_recovery OR EXISTS (SELECT 1 FROM public.billium_collection_dispatch_permit p WHERE p.step_id = w.step_id))
                  AND ((p_parked_step IS NULL AND w.status IN ('due', 'leased') AND w.next_attempt_at <= clock_timestamp())
                    OR (w.step_id = p_parked_step AND w.status = 'parked'))
                ORDER BY w.next_attempt_at, w.step_id
                LIMIT least(greatest(p_limit, 0), 100) FOR UPDATE OF w SKIP LOCKED
            $$;
            REVOKE ALL ON FUNCTION public.billium_collection_select_work(p_installation text, p_seller text, p_environment text, p_namespace text, p_profile text, p_recovery boolean, p_parked_step bigint, p_limit integer) FROM PUBLIC;
            SQL);

        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_collection_work');
        RuntimeGrants::grant('UPDATE (status, next_attempt_at, fence, delivery_claims, last_reason)', 'public.billium_collection_work');
        RuntimeGrants::grant('EXECUTE', 'FUNCTION public.billium_lock_collection_dunning_case(p_ids bigint[], p_seller text, p_environment text, p_shared boolean)');
        RuntimeGrants::grant('EXECUTE', 'FUNCTION public.billium_collection_select_work(p_installation text, p_seller text, p_environment text, p_namespace text, p_profile text, p_recovery boolean, p_parked_step bigint, p_limit integer)');
    }

    public function down(): never
    {
        throw new LogicException('Billium migrations are forward-only.');
    }
};
