<?php

declare(strict_types=1);

use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.billium_idempotency_receipt ADD COLUMN financial_scope jsonb;
            ALTER TABLE public.billium_idempotency_receipt ADD CONSTRAINT billium_idempotency_financial_scope CHECK (
                financial_scope IS NULL OR (
                    jsonb_typeof(financial_scope)='object'
                    AND financial_scope ?& ARRAY['installationRef','sellerRef','environment','intentOwnerNamespace','sourceAuthority']
                    AND financial_scope - ARRAY['installationRef','sellerRef','environment','intentOwnerNamespace','sourceAuthority']='{}'::jsonb
                    AND jsonb_typeof(financial_scope->'installationRef')='string'
                    AND length(financial_scope->>'installationRef') BETWEEN 1 AND 200
                    AND jsonb_typeof(financial_scope->'sellerRef')='string'
                    AND financial_scope->>'sellerRef'=seller_ref
                    AND jsonb_typeof(financial_scope->'environment')='string'
                    AND financial_scope->>'environment'=environment
                    AND (financial_scope->'intentOwnerNamespace'='null'::jsonb OR (
                        jsonb_typeof(financial_scope->'intentOwnerNamespace')='string'
                        AND length(financial_scope->>'intentOwnerNamespace') BETWEEN 1 AND 200))
                    AND (financial_scope->'sourceAuthority'='null'::jsonb OR (
                        jsonb_typeof(financial_scope->'sourceAuthority')='string'
                        AND length(financial_scope->>'sourceAuthority') BETWEEN 1 AND 200))
                )
            ) NOT VALID;
            CREATE FUNCTION public.billium_idempotency_scope_immutable() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            BEGIN
                IF NEW.financial_scope IS DISTINCT FROM OLD.financial_scope THEN
                    RAISE EXCEPTION 'billium: financial receipt scope is immutable' USING ERRCODE='55000';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_idempotency_scope_immutable() FROM PUBLIC;
            CREATE TRIGGER billium_idempotency_scope_immutable BEFORE UPDATE ON public.billium_idempotency_receipt
                FOR EACH ROW EXECUTE FUNCTION public.billium_idempotency_scope_immutable();
            CREATE INDEX billium_idempotency_opaque_receipt_scope
                ON public.billium_idempotency_receipt(financial_scope,(result->>'class'),(result#>>'{body,receiptRef}'),created_at,id)
                WHERE outcome='accepted' AND financial_scope IS NOT NULL;
            CREATE INDEX billium_outbox_delivery_destination_due
                ON public.billium_outbox_delivery(destination_ref,available_at,id) WHERE status='pending';
            CREATE INDEX billium_outbox_delivery_destination_expired
                ON public.billium_outbox_delivery(destination_ref,claim_until,id) WHERE status='in_flight';
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
