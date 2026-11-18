<?php

declare(strict_types=1);

use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.billium_idempotency_receipt ADD COLUMN input_digest text;
            ALTER TABLE public.billium_idempotency_receipt ADD CONSTRAINT billium_idempotency_input_digest CHECK (
                input_digest IS NULL OR (
                    financial_scope IS NOT NULL
                    AND octet_length(input_digest) = 71
                    AND input_digest ~ '^sha256:[0-9a-f]{64}$'
                )
            ) NOT VALID;
            
            CREATE FUNCTION public.billium_idempotency_input_digest_immutable() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            BEGIN
                IF NEW.input_digest IS DISTINCT FROM OLD.input_digest THEN
                    RAISE EXCEPTION 'billium: command receipt input digest is immutable' USING ERRCODE='55000';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_idempotency_input_digest_immutable() FROM PUBLIC;
            CREATE TRIGGER billium_idempotency_input_digest_immutable BEFORE UPDATE OF input_digest ON public.billium_idempotency_receipt
                FOR EACH ROW EXECUTE FUNCTION public.billium_idempotency_input_digest_immutable();
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
