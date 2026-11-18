<?php

declare(strict_types=1);

use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.billium_idempotency_receipt ADD COLUMN original_execution_binding_ref text NULL;
            ALTER TABLE public.billium_idempotency_receipt ADD CONSTRAINT billium_receipt_original_execution_binding_shape
                CHECK (original_execution_binding_ref IS NULL OR (
                    length(original_execution_binding_ref) BETWEEN 1 AND 200
                    AND financial_scope IS NOT NULL
                    AND command_kind IN ('payment.execute.v1','payment.authorize.v1','payment.capture.v1',
                        'payment.void_authorization.v1','payment.refund.v1','payment.cancel.v1')
                )) NOT VALID;
            CREATE FUNCTION public.billium_receipt_original_execution_binding_immutable() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                IF OLD.original_execution_binding_ref IS DISTINCT FROM NEW.original_execution_binding_ref THEN
                    RAISE EXCEPTION 'billium: original execution binding is immutable from claim'
                        USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_receipt_original_execution_binding_immutable() FROM PUBLIC;
            CREATE TRIGGER billium_receipt_original_execution_binding_immutable BEFORE UPDATE
                ON public.billium_idempotency_receipt FOR EACH ROW
                EXECUTE FUNCTION public.billium_receipt_original_execution_binding_immutable();
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
