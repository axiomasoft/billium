<?php

declare(strict_types=1);

use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.billium_execution_reservation_actual_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            BEGIN
                IF NEW.state='released' OR (NEW.state='consumed' AND NEW.consumed_minor<NEW.requested_minor) THEN
                    IF EXISTS (SELECT 1 FROM public.billium_execution_provider_fact f
                        WHERE f.installation_ref=NEW.installation_ref AND f.seller_ref=NEW.seller_ref AND f.environment=NEW.environment
                            AND f.operation_ref=NEW.operation_ref AND f.normalized_snapshot->>'bindingDigest' IS NOT NULL
                            AND (f.normalized_snapshot#>>'{operationAmount,minor}')::bigint > NEW.consumed_minor) THEN
                        RAISE EXCEPTION 'billium: reservation release contradicts original observed amount' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_reservation_actual_guard() FROM PUBLIC;
            CREATE TRIGGER billium_execution_reservation_actual_guard BEFORE UPDATE ON public.billium_execution_reservation
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_reservation_actual_guard();
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
