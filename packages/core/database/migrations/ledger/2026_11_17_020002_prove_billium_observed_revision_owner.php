<?php

declare(strict_types=1);

use Billium\Ledger\Internal\Repositories\LedgerPrivileges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_observed_revision_owner_proof (
                revision_id bigint PRIMARY KEY REFERENCES public.billium_observed_payment_revision(id),
                intent_owner_namespace text NOT NULL CHECK (intent_owner_namespace<>''),
                root_operation_ref text NOT NULL CHECK (root_operation_ref<>''),
                proof_digest text NOT NULL CHECK (proof_digest ~ '^sha256:[0-9a-f]{64}$')
            );
            CREATE FUNCTION public.billium_prove_observation_revision(
                p_installation text,p_seller text,p_env text,p_source text,p_account text,p_op text,p_root text,p_owner text,p_payload jsonb,p_proof text)
            RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,pg_temp AS $$
            DECLARE v_revision bigint;
            BEGIN
                SELECT r.id INTO v_revision FROM public.billium_observed_payment o JOIN public.billium_observed_payment_revision r ON r.observed_payment_id=o.id
                    WHERE (o.installation_ref,o.seller_ref,o.environment,o.source_authority,o.provider_account_ref,o.operation_ref,coalesce(o.original_ref,o.operation_ref))
                        =(p_installation,p_seller,p_env,p_source,p_account,p_op,p_root) AND r.payload=p_payload;
                IF NOT FOUND THEN RETURN 'absent'; END IF;
                INSERT INTO public.billium_observed_revision_owner_proof VALUES (v_revision,p_owner,p_root,p_proof)
                    ON CONFLICT ON CONSTRAINT billium_observed_revision_owner_proof_pkey DO NOTHING;
                IF NOT EXISTS (SELECT FROM public.billium_observed_revision_owner_proof proof WHERE proof.revision_id=v_revision
                        AND (proof.intent_owner_namespace,proof.root_operation_ref)=(p_owner,p_root)) THEN RETURN 'denied'; END IF;
                RETURN 'proven';
            END $$;
            REVOKE ALL ON FUNCTION public.billium_prove_observation_revision(text,text,text,text,text,text,text,text,jsonb,text) FROM PUBLIC;
            CREATE OR REPLACE FUNCTION public.billium_claim_observation_owner(
                p_installation text,p_seller text,p_env text,p_source text,p_account text,p_op text,p_root text,p_owner text,p_payload jsonb)
            RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,pg_temp AS $$
            DECLARE ref text; key text; binding public.billium_observation_owner_binding; historical public.billium_observed_payment;
            BEGIN
                -- Serialize against the established economic identity, including neutral callers and refund-first delivery.
                FOR ref IN SELECT DISTINCT v FROM unnest(ARRAY[p_op,p_root]) v ORDER BY v LOOP
                    PERFORM pg_advisory_xact_lock(hashtextextended(jsonb_build_array('billium.observation.owner',p_seller,p_env,p_source,ref)::text,0));
                END LOOP;
                FOR ref IN SELECT DISTINCT v FROM unnest(ARRAY[p_op,p_root]) v ORDER BY v LOOP
                    key := encode(sha256(convert_to(jsonb_build_array(p_seller,p_env,p_source,ref)::text,'UTF8')),'hex');
                    SELECT * INTO binding FROM public.billium_observation_owner_binding b WHERE b.operation_key=key;
                    IF FOUND THEN
                        IF (binding.installation_ref,binding.seller_ref,binding.environment,binding.source_authority,binding.provider_account_ref,
                            binding.operation_ref,binding.root_operation_ref,binding.intent_owner_namespace)
                            IS DISTINCT FROM (p_installation,p_seller,p_env,p_source,p_account,ref,p_root,p_owner) THEN RETURN 'denied'; END IF;
                    ELSIF p_owner IS NOT NULL THEN
                        SELECT * INTO historical FROM public.billium_observed_payment o
                        WHERE o.seller_ref=p_seller AND o.environment=p_env AND o.source_authority=p_source AND o.operation_ref=ref;
                        IF FOUND AND (historical.installation_ref<>p_installation OR historical.provider_account_ref<>p_account
                            OR coalesce(historical.original_ref,ref)<>p_root OR EXISTS (
                                SELECT FROM public.billium_observed_payment_revision r WHERE r.observed_payment_id=historical.id
                                AND NOT EXISTS (SELECT FROM public.billium_observed_revision_owner_proof proof WHERE proof.revision_id=r.id
                                    AND proof.intent_owner_namespace=p_owner AND proof.root_operation_ref=p_root))
                        ) THEN RETURN 'unproven'; END IF;
                    END IF;

                END LOOP;
                IF p_owner IS NOT NULL THEN
                    FOR ref IN SELECT DISTINCT v FROM unnest(ARRAY[p_op,p_root]) v ORDER BY v LOOP
                        key := encode(sha256(convert_to(jsonb_build_array(p_seller,p_env,p_source,ref)::text,'UTF8')),'hex');
                        INSERT INTO public.billium_observation_owner_binding VALUES (key,p_installation,p_seller,p_env,p_source,p_account,ref,p_root,p_owner)
                        ON CONFLICT (operation_key) DO NOTHING;
                        IF NOT EXISTS (SELECT FROM public.billium_observation_owner_binding b WHERE b.operation_key=key
                            AND (b.installation_ref,b.seller_ref,b.environment,b.source_authority,b.provider_account_ref,b.operation_ref,b.root_operation_ref,b.intent_owner_namespace)
                              =(p_installation,p_seller,p_env,p_source,p_account,ref,p_root,p_owner)) THEN RAISE EXCEPTION 'billium.observation.conflict'; END IF;
                    END LOOP;
                END IF;
                RETURN 'accepted';
            END $$;
            SQL);
        app(LedgerPrivileges::class)->grant();
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
