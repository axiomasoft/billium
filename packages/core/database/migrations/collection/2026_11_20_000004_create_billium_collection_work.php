<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

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

            INSERT INTO public.billium_collection_work(step_id, seller_ref, environment, next_attempt_at)
            SELECT a.step_id, a.seller_ref, a.environment, a.issued_at
            FROM public.billium_collection_authorization a
            JOIN public.billium_collection_step s ON s.id = a.step_id
            WHERE s.status IN ('authorized', 'dispatched', 'unknown');

            CREATE FUNCTION public.billium_lock_collection_dunning_case(
                p_ids bigint[], p_seller text, p_environment text, p_shared boolean
            ) RETURNS TABLE(lock_key bigint) LANGUAGE sql SET search_path = pg_catalog, pg_temp AS $$
                SELECT c.id FROM public.billium_collection_dunning_case c
                WHERE c.id = ANY(p_ids) AND c.seller_ref = p_seller AND c.environment = p_environment AND NOT p_shared
                ORDER BY c.id FOR UPDATE SKIP LOCKED
            $$;

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
            SQL);

        $runtime = config('billium.connection') ?? config('database.default');
        $username = DB::connection($runtime)->getConfig('username');
        if (! is_string($username) || $username === '') {
            throw new LogicException('Collection runtime role is required');
        }
        $role = '"'.str_replace('"', '""', $username).'"';
        DB::unprepared('REVOKE ALL ON TABLE public.billium_collection_work FROM PUBLIC, '.$role);
        DB::unprepared('GRANT SELECT, INSERT ON TABLE public.billium_collection_work TO '.$role);
        DB::unprepared('GRANT UPDATE (status, next_attempt_at, fence, delivery_claims, last_reason) ON TABLE public.billium_collection_work TO '.$role);
        foreach (['billium_lock_collection_dunning_case(bigint[], text, text, boolean)',
            'billium_collection_select_work(text, text, text, text, text, boolean, bigint, integer)'] as $function) {
            DB::unprepared('REVOKE ALL ON FUNCTION public.'.$function.' FROM PUBLIC, '.$role);
            DB::unprepared('GRANT EXECUTE ON FUNCTION public.'.$function.' TO '.$role);
        }
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
