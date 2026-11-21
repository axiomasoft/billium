<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ACC-RST-10: fixture only, outside production migration identities (D21).
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            SET lock_timeout = '2s';
            ALTER TABLE public.billium_allocation ADD COLUMN IF NOT EXISTS drill_note text NULL;
            ALTER TABLE public.billium_allocation ADD CONSTRAINT billium_drill_note_length
                CHECK (drill_note IS NULL OR length(drill_note) <= 64) NOT VALID;
            CREATE OR REPLACE FUNCTION public.billium_drill_note() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                NEW.drill_note := nullif(current_setting('billium.drill_note', true), '');
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_drill_note() FROM PUBLIC;
            CREATE TRIGGER billium_drill_note BEFORE INSERT ON public.billium_allocation
                FOR EACH ROW EXECUTE FUNCTION public.billium_drill_note();
            -- Permit only the new non-economic field to be backfilled; every original field remains immutable.
            CREATE OR REPLACE FUNCTION public.billium_drill_allocation_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP='DELETE' OR (to_jsonb(NEW)-'drill_note') IS DISTINCT FROM (to_jsonb(OLD)-'drill_note')
                THEN RAISE EXCEPTION 'billium: append-only economic allocation' USING ERRCODE='55000'; END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_drill_allocation_guard() FROM PUBLIC;
            DROP TRIGGER billium_allocation_append_only ON public.billium_allocation;
            CREATE TRIGGER billium_allocation_append_only BEFORE UPDATE OR DELETE ON public.billium_allocation
                FOR EACH ROW EXECUTE FUNCTION public.billium_drill_allocation_guard();
            RESET lock_timeout;
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('Contract is deferred until after the rollback window (D21).');
    }
};
