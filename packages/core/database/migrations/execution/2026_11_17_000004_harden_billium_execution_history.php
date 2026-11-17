<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.billium_execution_immutable() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            BEGIN
                IF TG_OP='DELETE' THEN
                    RAISE EXCEPTION 'billium: execution history is never deleted' USING ERRCODE='55000';
                END IF;
                IF (to_jsonb(NEW)-coalesce(TG_ARGV,ARRAY[]::text[])) IS DISTINCT FROM
                   (to_jsonb(OLD)-coalesce(TG_ARGV,ARRAY[]::text[])) THEN
                    RAISE EXCEPTION 'billium: execution immutable identity/history' USING ERRCODE='55000';
                END IF;
                IF TG_TABLE_NAME='billium_execution_observation_emission' AND to_jsonb(OLD)->>'command_row_id' IS NOT NULL THEN
                    RAISE EXCEPTION 'billium: emission is complete' USING ERRCODE='55000';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_immutable() FROM PUBLIC;

            CREATE FUNCTION public.billium_execution_reservation_proof_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            DECLARE e public.billium_execution_provider_evidence; r public.billium_execution_operation;
            BEGIN
                IF NEW.state='released' OR (NEW.state='consumed' AND NEW.consumed_minor<NEW.requested_minor) THEN
                    SELECT * INTO e FROM public.billium_execution_provider_evidence WHERE installation_ref=NEW.installation_ref
                        AND seller_ref=NEW.seller_ref AND environment=NEW.environment AND evidence_ref=NEW.release_evidence_ref;
                    SELECT * INTO r FROM public.billium_execution_operation WHERE installation_ref=NEW.installation_ref
                        AND seller_ref=NEW.seller_ref AND environment=NEW.environment AND operation_ref=NEW.root_operation_ref;
                    IF e.id IS NULL OR r.id IS NULL OR e.signature_verdict<>'verified_no_effect' OR e.merchant_account_ref<>r.merchant_account_ref THEN
                        RAISE EXCEPTION 'billium: reservation release needs account-scoped no-effect evidence' USING ERRCODE='23514'; END IF;
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_reservation_proof_guard() FROM PUBLIC;
            CREATE TRIGGER billium_execution_reservation_proof_guard BEFORE UPDATE ON public.billium_execution_reservation
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_reservation_proof_guard();
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
