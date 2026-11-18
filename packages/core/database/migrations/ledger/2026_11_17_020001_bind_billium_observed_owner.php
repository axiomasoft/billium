<?php

declare(strict_types=1);

use Billium\Ledger\Internal\Repositories\LedgerPrivileges;
use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_observation_owner_binding (
                operation_key text PRIMARY KEY CHECK (operation_key ~ '^[0-9a-f]{64}$'),
                installation_ref text NOT NULL CHECK (installation_ref <> ''),
                seller_ref text NOT NULL CHECK (seller_ref <> ''),
                environment text NOT NULL CHECK (environment <> ''),
                source_authority text NOT NULL CHECK (source_authority <> ''),
                provider_account_ref text NOT NULL CHECK (provider_account_ref <> ''),
                operation_ref text NOT NULL CHECK (operation_ref <> ''),
                root_operation_ref text NOT NULL CHECK (root_operation_ref <> ''),
                intent_owner_namespace text NOT NULL CHECK (intent_owner_namespace <> '')
            );
            CREATE FUNCTION public.billium_claim_observation_owner(
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
                            OR ref<>p_op OR coalesce(historical.original_ref,ref)<>p_root OR EXISTS (
                                SELECT FROM public.billium_observed_payment_revision r WHERE r.observed_payment_id=historical.id AND r.payload<>p_payload)
                        ) THEN RETURN 'unproven'; END IF;
                    END IF;

                END LOOP;
                IF p_owner IS NOT NULL THEN
                    FOR ref IN SELECT DISTINCT v FROM unnest(ARRAY[p_op,p_root]) v ORDER BY v LOOP
                        key := encode(sha256(convert_to(jsonb_build_array(p_seller,p_env,p_source,ref)::text,'UTF8')),'hex');
                        INSERT INTO public.billium_observation_owner_binding VALUES (key,p_installation,p_seller,p_env,p_source,p_account,ref,p_root,p_owner)
                        ON CONFLICT (operation_key) DO NOTHING;
                    END LOOP;
                END IF;
                RETURN 'accepted';
            END $$;
            REVOKE ALL ON FUNCTION public.billium_claim_observation_owner(text,text,text,text,text,text,text,text,jsonb) FROM PUBLIC;
            SQL);
        app(LedgerPrivileges::class)->grant();
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
