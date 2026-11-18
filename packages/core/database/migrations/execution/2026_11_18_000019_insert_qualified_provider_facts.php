<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX billium_execution_qualified_provider_fact ON public.billium_execution_provider_fact(provider_identity_scope,fact_identity)
                WHERE normalized_snapshot->>'bindingDigest' IS NOT NULL AND normalized_snapshot->>'operationAmount' IS NOT NULL;

            CREATE FUNCTION public.billium_execution_accept_provider_fact(f jsonb, i jsonb) RETURNS boolean
            LANGUAGE plpgsql SECURITY INVOKER SET search_path=pg_catalog,pg_temp AS $$
            BEGIN
                INSERT INTO public.billium_execution_provider_fact
                    (installation_ref,seller_ref,environment,fact_ref,operation_ref,provider_identity_scope,fact_identity,evidence_mode,material_digest,normalized_snapshot,evidence_refs)
                    VALUES (f->>'installation_ref',f->>'seller_ref',f->>'environment',f->>'fact_ref',f->>'operation_ref',f->>'provider_identity_scope',
                        f->>'fact_identity',f->>'evidence_mode',f->>'material_digest',f->'normalized_snapshot',f->'evidence_refs') ON CONFLICT DO NOTHING;
                IF NOT FOUND THEN RETURN false; END IF;
                IF i IS NOT NULL THEN
                    INSERT INTO public.billium_execution_provider_identity
                        (installation_ref,seller_ref,environment,attempt_ref,provider_id,merchant_account_ref,provider_namespace,provider_operation_id,evidence_ref)
                        VALUES (f->>'installation_ref',f->>'seller_ref',f->>'environment',i->>'attempt_ref',i->>'provider_id',i->>'merchant_account_ref',
                            i->>'provider_namespace',i->>'provider_operation_id',i->>'evidence_ref') ON CONFLICT DO NOTHING;
                    IF NOT FOUND AND NOT EXISTS (SELECT 1 FROM public.billium_execution_provider_identity
                        WHERE installation_ref=f->>'installation_ref' AND seller_ref=f->>'seller_ref' AND environment=f->>'environment'
                            AND attempt_ref=i->>'attempt_ref' AND provider_id=i->>'provider_id' AND merchant_account_ref=i->>'merchant_account_ref'
                            AND provider_namespace=i->>'provider_namespace' AND provider_operation_id=i->>'provider_operation_id') THEN
                        RAISE EXCEPTION 'billium: conflicting assigned provider operation' USING ERRCODE='BX001';
                    END IF;
                END IF;
                RETURN true;
            EXCEPTION WHEN SQLSTATE 'BX001' THEN
                -- Roll back both inserts from this statement while preserving the raw delivery and its quarantine transaction.
                RETURN false;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_accept_provider_fact(jsonb,jsonb) FROM PUBLIC;
            SQL);
        RuntimeGrants::grant('EXECUTE', 'FUNCTION public.billium_execution_accept_provider_fact(jsonb,jsonb)');
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
