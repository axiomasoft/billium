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
            CREATE OR REPLACE FUNCTION public.billium_lock_execution_merchant_revision(p_ids bigint[], p_seller text, p_environment text, p_shared boolean)
            RETURNS TABLE (lock_key bigint) LANGUAGE plpgsql STRICT SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE installation text := current_setting('billium.merchant_gate_installation', true);
            BEGIN
                IF p_shared THEN
                    RETURN QUERY SELECT r.id FROM public.billium_execution_merchant_revision r
                        WHERE r.id = ANY(p_ids) AND r.installation_ref = installation AND r.seller_ref = p_seller
                            AND r.environment = p_environment ORDER BY r.id FOR SHARE;
                ELSE
                    RETURN QUERY SELECT r.id FROM public.billium_execution_merchant_revision r
                        WHERE r.id = ANY(p_ids) AND r.installation_ref = installation AND r.seller_ref = p_seller
                            AND r.environment = p_environment ORDER BY r.id FOR UPDATE;
                END IF;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_lock_execution_merchant_revision(bigint[], text, text, boolean) FROM PUBLIC;

            -- The runtime can INSERT events directly. Those inserts must share the same barrier as the typed writers.
            CREATE OR REPLACE FUNCTION public.billium_execution_lock_merchant_writer() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                PERFORM r.id FROM public.billium_execution_merchant_revision r WHERE r.installation_ref = NEW.installation_ref
                    AND r.seller_ref = NEW.seller_ref AND r.environment = NEW.environment AND r.revision_ref = NEW.revision_ref FOR UPDATE;
                IF NOT FOUND THEN RAISE EXCEPTION 'billium: original merchant revision unavailable' USING ERRCODE = '23503'; END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_lock_merchant_writer() FROM PUBLIC;
            CREATE TRIGGER billium_execution_admission_writer_lock BEFORE INSERT ON public.billium_execution_merchant_lifecycle_event
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_lock_merchant_writer();
            CREATE TRIGGER billium_execution_admission_writer_lock BEFORE INSERT ON public.billium_execution_merchant_credential_revision
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_lock_merchant_writer();
            SQL);
        $role = RuntimeGrants::role();
        if ($role !== null) {
            DB::connection()->getPdo()->exec('GRANT EXECUTE ON FUNCTION public.billium_lock_execution_merchant_revision(bigint[],text,text,boolean) TO "'.str_replace('"', '""', $role).'"');
        }
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
