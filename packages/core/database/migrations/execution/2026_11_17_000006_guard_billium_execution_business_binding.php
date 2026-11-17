<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.billium_execution_root_identity_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            DECLARE b public.billium_execution_business_binding;
            BEGIN
                IF NEW.operation_ref=NEW.root_operation_ref THEN
                    SELECT * INTO b FROM public.billium_execution_business_binding WHERE installation_ref=NEW.installation_ref
                        AND environment=NEW.environment AND intent_owner_namespace=NEW.intent_owner_namespace
                        AND business_intent_ref=NEW.business_intent_ref AND operation_kind='charge';
                    IF b.id IS NULL OR (b.seller_ref,b.operation_ref,b.semantic_digest,b.binding) IS DISTINCT FROM
                        (NEW.seller_ref,NEW.operation_ref,NEW.economic_digest,NEW.binding) THEN
                        RAISE EXCEPTION 'billium: root operation requires original durable business identity' USING ERRCODE='23514'; END IF;
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_root_identity_guard() FROM PUBLIC;
            CREATE TRIGGER billium_execution_root_identity_guard BEFORE INSERT ON public.billium_execution_operation
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_root_identity_guard();
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
