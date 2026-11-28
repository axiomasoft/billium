<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

/**
 * Execution runtime: namespaces, merchant revisions and capabilities, business bindings, operations, capacity,
 * reservations, attempts, provider facts and evidence, authorizations, dispatch and provider work.
 */
return new class extends PackmanMigration
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

            CREATE TABLE public.billium_execution_namespace (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                intent_owner_namespace text NOT NULL,
                state text NOT NULL,
                canonicalization_version text NOT NULL,
                history_from timestamptz,
                completeness_receipt_ref text,
                generation bigint NOT NULL DEFAULT 1,
                UNIQUE(installation_ref,seller_ref,environment,intent_owner_namespace),
                CHECK(state IN ('open','closed','recovery_required')),
                CHECK(generation>=1),
                CHECK(state<>'open' OR completeness_receipt_ref IS NOT NULL),
                CHECK(length(intent_owner_namespace) BETWEEN 1 AND 200),
                CHECK(length(state) BETWEEN 1 AND 200),
                CHECK(length(canonicalization_version) BETWEEN 1 AND 200)
            );

            CREATE TRIGGER billium_execution_namespace_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_namespace FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('state','history_from','completeness_receipt_ref','generation');

            CREATE TABLE public.billium_execution_capability_snapshot (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                snapshot_ref text NOT NULL,
                merchant_account_ref text NOT NULL,
                driver_id text NOT NULL,
                driver_contract_version text NOT NULL,
                capability_version text NOT NULL,
                body jsonb NOT NULL,
                digest text NOT NULL,
                qualification_mode text NOT NULL,
                qualification_evidence_ref text NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                supported_effects text[] NOT NULL,
                currencies text[] NOT NULL,
                capture_mode text NOT NULL,
                partial_refund boolean NOT NULL,
                multiple_refund boolean NOT NULL,
                lookup_by_reference boolean NOT NULL,
                lookup_by_provider_id boolean NOT NULL,
                inventory_complete boolean NOT NULL,
                absence_certification boolean NOT NULL,
                idempotency_retention_seconds bigint,
                lookup_retention_seconds bigint,
                evidence_mode text NOT NULL,
                UNIQUE(installation_ref,seller_ref,environment,snapshot_ref),
                UNIQUE(installation_ref,seller_ref,environment,snapshot_ref,merchant_account_ref,driver_id,driver_contract_version,digest),
                CHECK(qualification_mode IN ('simulated','provider_verified')),
                CHECK(capture_mode IN ('none','full','partial_single','partial_multiple')),
                CHECK(cardinality(supported_effects)>0 AND cardinality(currencies)>0),
                CHECK(NOT absence_certification OR (inventory_complete AND lookup_by_reference)),
                CHECK(idempotency_retention_seconds IS NULL OR idempotency_retention_seconds>0),
                CHECK(lookup_retention_seconds IS NULL OR lookup_retention_seconds>0),
                CHECK(length(snapshot_ref) BETWEEN 1 AND 200),
                CHECK(length(merchant_account_ref) BETWEEN 1 AND 200),
                CHECK(length(driver_id) BETWEEN 1 AND 200),
                CHECK(length(driver_contract_version) BETWEEN 1 AND 200),
                CHECK(length(capability_version) BETWEEN 1 AND 200),
                CHECK(length(digest) BETWEEN 1 AND 200),
                CHECK(length(qualification_mode) BETWEEN 1 AND 200),
                CHECK(length(qualification_evidence_ref) BETWEEN 1 AND 200),
                CHECK(length(capture_mode) BETWEEN 1 AND 200),
                CHECK(length(evidence_mode) BETWEEN 1 AND 200),
                CHECK(length(capture_mode) BETWEEN 1 AND 200)
            );

            CREATE TABLE public.billium_execution_merchant_revision (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                revision_ref text NOT NULL,
                merchant_account_ref text NOT NULL,
                provider_id text NOT NULL,
                driver_id text NOT NULL,
                driver_contract_version text NOT NULL,
                capability_snapshot_ref text NOT NULL,
                capability_digest text NOT NULL,
                credential_ref text NOT NULL,
                credential_version_ref text NOT NULL,
                currency_codes jsonb NOT NULL,
                digest text NOT NULL,
                created_at timestamptz NOT NULL,
                valid_from timestamptz NOT NULL,
                valid_until timestamptz,
                predecessor_ref text,
                api_profile_version text NOT NULL,
                webhook_route_ref text NOT NULL,
                continuity_evidence_ref text NOT NULL,
                settlement_account_ref text NOT NULL,
                mandate_namespace_ref text NOT NULL,
                UNIQUE(installation_ref,seller_ref,environment,revision_ref),
                UNIQUE(installation_ref,seller_ref,environment,revision_ref,merchant_account_ref,driver_id,driver_contract_version,capability_digest),
                CHECK(credential_ref ~ '^secret://[A-Za-z0-9][A-Za-z0-9/._:-]*$'),
                CHECK(valid_until IS NULL OR valid_until>valid_from),
                CHECK(jsonb_typeof(currency_codes)='array'),
                CHECK(length(revision_ref) BETWEEN 1 AND 200),
                CHECK(length(merchant_account_ref) BETWEEN 1 AND 200),
                CHECK(length(provider_id) BETWEEN 1 AND 200),
                CHECK(length(driver_id) BETWEEN 1 AND 200),
                CHECK(length(driver_contract_version) BETWEEN 1 AND 200),
                CHECK(length(capability_snapshot_ref) BETWEEN 1 AND 200),
                CHECK(length(capability_digest) BETWEEN 1 AND 200),
                CHECK(length(credential_ref) BETWEEN 1 AND 200),
                CHECK(length(credential_version_ref) BETWEEN 1 AND 200),
                CHECK(length(digest) BETWEEN 1 AND 200),
                CHECK(length(api_profile_version) BETWEEN 1 AND 200),
                CHECK(length(webhook_route_ref) BETWEEN 1 AND 200),
                CONSTRAINT ex_merchant_continuity_required CHECK (continuity_evidence_ref IS NOT NULL AND length(continuity_evidence_ref) BETWEEN 1 AND 200),
                CONSTRAINT ex_merchant_settlement_namespace CHECK
                    (settlement_account_ref IS NOT NULL AND length(settlement_account_ref) BETWEEN 1 AND 200
                    AND mandate_namespace_ref IS NOT NULL AND length(mandate_namespace_ref) BETWEEN 1 AND 200),
                CONSTRAINT ex_merchant_revision_capability_snapshot_fk FOREIGN KEY (installation_ref,seller_ref,environment,capability_snapshot_ref,merchant_account_ref,driver_id,driver_contract_version,capability_digest)
                    REFERENCES public.billium_execution_capability_snapshot(installation_ref,seller_ref,environment,snapshot_ref,merchant_account_ref,driver_id,driver_contract_version,digest)
            );

            CREATE TRIGGER billium_execution_merchant_revision_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_merchant_revision FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();

            CREATE INDEX billium_execution_merchant_revision_lookup ON public.billium_execution_merchant_revision(installation_ref,seller_ref,environment,merchant_account_ref,valid_from,id);

            CREATE TRIGGER billium_execution_capability_snapshot_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_capability_snapshot FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();

            CREATE UNIQUE INDEX ex_merchant_seller_financial_namespaces ON public.billium_execution_merchant_revision
                (installation_ref,seller_ref,environment,revision_ref,merchant_account_ref,settlement_account_ref,mandate_namespace_ref);

            -- RFC 8785 for this closed schema of string fields: constant UTF-16 key order and JSON string escaping.
            CREATE FUNCTION public.billium_execution_business_identity_digest(text,text,text,text,text) RETURNS text
            LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE SET search_path=pg_catalog,pg_temp AS $$
                SELECT 'sha256:' || encode(sha256(convert_to(
                    '{"businessIntentRef":' || to_json($4)::text ||
                    ',"environment":' || to_json($2)::text ||
                    ',"intentOwnerNamespace":' || to_json($1)::text ||
                    ',"operationKind":' || to_json($5)::text ||
                    ',"schema":"billium.execution.business-identity.v1","sourceContext":' || to_json($3)::text || '}',
                    'UTF8')), 'hex')
            $$;
            REVOKE ALL ON FUNCTION public.billium_execution_business_identity_digest(text, text, text, text, text) FROM PUBLIC;

            CREATE TABLE public.billium_execution_business_binding (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                intent_owner_namespace text NOT NULL,
                business_intent_ref text NOT NULL,
                operation_kind text NOT NULL,
                semantic_digest text NOT NULL,
                canonicalization_version text NOT NULL,
                operation_ref text NOT NULL,
                binding jsonb NOT NULL,
                tombstone_reason text,
                created_at timestamptz NOT NULL DEFAULT now(),
                source_context text,
                business_identity_digest text
                    GENERATED ALWAYS AS (public.billium_execution_business_identity_digest(intent_owner_namespace,environment,source_context,business_intent_ref,operation_kind)) STORED,
                retention_policy text NOT NULL
                    DEFAULT 'execution-business-identity-indefinite-v1',
                retention_deadline_at timestamptz,
                UNIQUE(installation_ref,seller_ref,environment,operation_ref),
                CHECK(operation_kind IN ('charge','capture','void_authorization','refund')),
                CHECK(length(intent_owner_namespace) BETWEEN 1 AND 200),
                CHECK(length(business_intent_ref) BETWEEN 1 AND 200),
                CHECK(length(operation_kind) BETWEEN 1 AND 200),
                CHECK(length(semantic_digest) BETWEEN 1 AND 200),
                CHECK(length(canonicalization_version) BETWEEN 1 AND 200),
                CHECK(length(operation_ref) BETWEEN 1 AND 200),
                CONSTRAINT ex_business_source_context CHECK (
                    source_context IS NOT NULL AND source_context <> '' AND
                    (jsonb_typeof(binding->'sourceContext'), binding->>'sourceContext', binding->>'businessIntentRef',
                    binding->>'rootOperationRef', binding->>'semanticDigest', binding->>'canonicalizationVersion',
                    binding#>>'{scope,installationRef}', binding#>>'{scope,sellerRef}', binding#>>'{scope,environment}',
                    binding#>>'{scope,intentOwnerNamespace}') IS NOT DISTINCT FROM
                    ('string', source_context, business_intent_ref, operation_ref, semantic_digest, canonicalization_version,
                    installation_ref, seller_ref, environment, intent_owner_namespace)
                    AND (binding->>'executionProfile' IN ('remote-billium','standalone-billium')) IS TRUE
                    ),
                CONSTRAINT ex_business_identity_retention
                    CHECK (retention_policy = 'execution-business-identity-indefinite-v1' AND retention_deadline_at IS NULL)
            );

            CREATE TRIGGER billium_execution_business_binding_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_business_binding FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();

            CREATE UNIQUE INDEX billium_execution_business_identity_digest_unique ON public.billium_execution_business_binding (business_identity_digest);

            CREATE TABLE public.billium_execution_operation (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                operation_ref text NOT NULL,
                root_operation_ref text NOT NULL,
                kind text NOT NULL,
                binding jsonb NOT NULL,
                binding_digest text NOT NULL,
                business_intent_ref text NOT NULL,
                intent_owner_namespace text NOT NULL,
                merchant_revision_ref text NOT NULL,
                merchant_account_ref text NOT NULL,
                driver_id text NOT NULL,
                driver_contract_version text NOT NULL,
                capability_digest text NOT NULL,
                requested_minor bigint NOT NULL,
                currency text NOT NULL,
                currency_exponent integer NOT NULL,
                state text NOT NULL,
                revision bigint NOT NULL DEFAULT 0,
                economic_digest text NOT NULL,
                authorization_ref text,
                collection_authorization_ref text,
                created_at timestamptz NOT NULL DEFAULT now(),
                next_reconcile_at timestamptz,
                last_reconciled_at timestamptz,
                reconcile_attempts bigint NOT NULL DEFAULT 0,
                reconcile_state text NOT NULL DEFAULT 'due',
                source_context text,
                UNIQUE(installation_ref,seller_ref,environment,operation_ref),
                CHECK(kind IN ('charge','authorize','capture','void_authorization','refund')),
                CHECK(requested_minor>0 AND revision>=0),
                CHECK((kind IN ('charge','authorize')) = (operation_ref=root_operation_ref)),
                CHECK(state IN ('admitted','pending','unknown','succeeded','failed','cancelled','recovery_required','partially_captured','authorized','captured','refunded','voided','expired','held')),
                CHECK(currency ~ '^[A-Z]{3}$' AND currency_exponent BETWEEN 0 AND 4),
                CHECK(length(operation_ref) BETWEEN 1 AND 200),
                CHECK(length(root_operation_ref) BETWEEN 1 AND 200),
                CHECK(length(kind) BETWEEN 1 AND 200),
                CHECK(length(binding_digest) BETWEEN 1 AND 200),
                CHECK(length(business_intent_ref) BETWEEN 1 AND 200),
                CHECK(length(intent_owner_namespace) BETWEEN 1 AND 200),
                CHECK(length(merchant_revision_ref) BETWEEN 1 AND 200),
                CHECK(length(merchant_account_ref) BETWEEN 1 AND 200),
                CHECK(length(driver_id) BETWEEN 1 AND 200),
                CHECK(length(driver_contract_version) BETWEEN 1 AND 200),
                CHECK(length(capability_digest) BETWEEN 1 AND 200),
                CHECK(length(currency) BETWEEN 1 AND 200),
                CHECK(length(state) BETWEEN 1 AND 200),
                CHECK(length(economic_digest) BETWEEN 1 AND 200),
                CHECK(length(reconcile_state) BETWEEN 1 AND 200),
                CONSTRAINT ex_operation_merchant_revision_fk FOREIGN KEY (installation_ref,seller_ref,environment,merchant_revision_ref,merchant_account_ref,driver_id,driver_contract_version,capability_digest) REFERENCES public.billium_execution_merchant_revision(installation_ref,seller_ref,environment,revision_ref,merchant_account_ref,driver_id,driver_contract_version,capability_digest),
                CONSTRAINT ex_operation_operation_fk FOREIGN KEY (installation_ref,seller_ref,environment,root_operation_ref) REFERENCES public.billium_execution_operation(installation_ref,seller_ref,environment,operation_ref),
                CONSTRAINT ex_operation_currency_scale CHECK (currency_exponent=(CASE currency WHEN 'AED' THEN 2 WHEN 'AFN' THEN 2 WHEN 'ALL' THEN 2 WHEN 'AMD' THEN 2 WHEN 'AOA' THEN 2 WHEN 'ARS' THEN 2 WHEN 'AUD' THEN 2 WHEN 'AWG' THEN 2 WHEN 'AZN' THEN 2 WHEN 'BAM' THEN 2 WHEN 'BBD' THEN 2 WHEN 'BDT' THEN 2 WHEN 'BHD' THEN 3 WHEN 'BIF' THEN 0 WHEN 'BMD' THEN 2 WHEN 'BND' THEN 2 WHEN 'BOB' THEN 2 WHEN 'BOV' THEN 2 WHEN 'BRL' THEN 2 WHEN 'BSD' THEN 2 WHEN 'BTN' THEN 2 WHEN 'BWP' THEN 2 WHEN 'BYN' THEN 2 WHEN 'BZD' THEN 2 WHEN 'CAD' THEN 2 WHEN 'CDF' THEN 2 WHEN 'CHE' THEN 2 WHEN 'CHF' THEN 2 WHEN 'CHW' THEN 2 WHEN 'CLF' THEN 4 WHEN 'CLP' THEN 0 WHEN 'CNY' THEN 2 WHEN 'COP' THEN 2 WHEN 'COU' THEN 2 WHEN 'CRC' THEN 2 WHEN 'CUP' THEN 2 WHEN 'CVE' THEN 2 WHEN 'CZK' THEN 2 WHEN 'DJF' THEN 0 WHEN 'DKK' THEN 2 WHEN 'DOP' THEN 2 WHEN 'DZD' THEN 2 WHEN 'EGP' THEN 2 WHEN 'ERN' THEN 2 WHEN 'ETB' THEN 2 WHEN 'EUR' THEN 2 WHEN 'FJD' THEN 2 WHEN 'FKP' THEN 2 WHEN 'GBP' THEN 2 WHEN 'GEL' THEN 2 WHEN 'GHS' THEN 2 WHEN 'GIP' THEN 2 WHEN 'GMD' THEN 2 WHEN 'GNF' THEN 0 WHEN 'GTQ' THEN 2 WHEN 'GYD' THEN 2 WHEN 'HKD' THEN 2 WHEN 'HNL' THEN 2 WHEN 'HTG' THEN 2 WHEN 'HUF' THEN 2 WHEN 'IDR' THEN 2 WHEN 'ILS' THEN 2 WHEN 'INR' THEN 2 WHEN 'IQD' THEN 3 WHEN 'IRR' THEN 2 WHEN 'ISK' THEN 0 WHEN 'JMD' THEN 2 WHEN 'JOD' THEN 3 WHEN 'JPY' THEN 0 WHEN 'KES' THEN 2 WHEN 'KGS' THEN 2 WHEN 'KHR' THEN 2 WHEN 'KMF' THEN 0 WHEN 'KPW' THEN 2 WHEN 'KRW' THEN 0 WHEN 'KWD' THEN 3 WHEN 'KYD' THEN 2 WHEN 'KZT' THEN 2 WHEN 'LAK' THEN 2 WHEN 'LBP' THEN 2 WHEN 'LKR' THEN 2 WHEN 'LRD' THEN 2 WHEN 'LSL' THEN 2 WHEN 'LYD' THEN 3 WHEN 'MAD' THEN 2 WHEN 'MDL' THEN 2 WHEN 'MGA' THEN 2 WHEN 'MKD' THEN 2 WHEN 'MMK' THEN 2 WHEN 'MNT' THEN 2 WHEN 'MOP' THEN 2 WHEN 'MRU' THEN 2 WHEN 'MUR' THEN 2 WHEN 'MVR' THEN 2 WHEN 'MWK' THEN 2 WHEN 'MXN' THEN 2 WHEN 'MXV' THEN 2 WHEN 'MYR' THEN 2 WHEN 'MZN' THEN 2 WHEN 'NAD' THEN 2 WHEN 'NGN' THEN 2 WHEN 'NIO' THEN 2 WHEN 'NOK' THEN 2 WHEN 'NPR' THEN 2 WHEN 'NZD' THEN 2 WHEN 'OMR' THEN 3 WHEN 'PAB' THEN 2 WHEN 'PEN' THEN 2 WHEN 'PGK' THEN 2 WHEN 'PHP' THEN 2 WHEN 'PKR' THEN 2 WHEN 'PLN' THEN 2 WHEN 'PYG' THEN 0 WHEN 'QAR' THEN 2 WHEN 'RON' THEN 2 WHEN 'RSD' THEN 2 WHEN 'RUB' THEN 2 WHEN 'RWF' THEN 0 WHEN 'SAR' THEN 2 WHEN 'SBD' THEN 2 WHEN 'SCR' THEN 2 WHEN 'SDG' THEN 2 WHEN 'SEK' THEN 2 WHEN 'SGD' THEN 2 WHEN 'SHP' THEN 2 WHEN 'SLE' THEN 2 WHEN 'SOS' THEN 2 WHEN 'SRD' THEN 2 WHEN 'SSP' THEN 2 WHEN 'STN' THEN 2 WHEN 'SVC' THEN 2 WHEN 'SYP' THEN 2 WHEN 'SZL' THEN 2 WHEN 'THB' THEN 2 WHEN 'TJS' THEN 2 WHEN 'TMT' THEN 2 WHEN 'TND' THEN 3 WHEN 'TOP' THEN 2 WHEN 'TRY' THEN 2 WHEN 'TTD' THEN 2 WHEN 'TWD' THEN 2 WHEN 'TZS' THEN 2 WHEN 'UAH' THEN 2 WHEN 'UGX' THEN 0 WHEN 'USD' THEN 2 WHEN 'USN' THEN 2 WHEN 'UYI' THEN 0 WHEN 'UYU' THEN 2 WHEN 'UYW' THEN 4 WHEN 'UZS' THEN 2 WHEN 'VED' THEN 2 WHEN 'VES' THEN 2 WHEN 'VND' THEN 0 WHEN 'VUV' THEN 0 WHEN 'WST' THEN 2 WHEN 'XAD' THEN 2 WHEN 'XAF' THEN 0 WHEN 'XCD' THEN 2 WHEN 'XCG' THEN 2 WHEN 'XOF' THEN 0 WHEN 'XPF' THEN 0 WHEN 'YER' THEN 2 WHEN 'ZAR' THEN 2 WHEN 'ZMW' THEN 2 WHEN 'ZWG' THEN 2 ELSE -1 END)),
                CONSTRAINT ex_operation_source_context CHECK (
                    source_context IS NOT NULL AND source_context <> '' AND
                    (jsonb_typeof(binding->'sourceContext'), binding->>'sourceContext', binding->>'businessIntentRef') IS NOT DISTINCT FROM
                    ('string', source_context, business_intent_ref)
                    AND (binding->>'executionProfile' IN ('remote-billium','standalone-billium')) IS TRUE
                    )
            );

            CREATE TRIGGER billium_execution_operation_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_operation FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('state','revision','economic_digest','authorization_ref','next_reconcile_at','last_reconciled_at','reconcile_attempts','reconcile_state');

            CREATE INDEX billium_execution_operation_lookup ON public.billium_execution_operation(installation_ref,seller_ref,environment,state,next_reconcile_at,id);

            CREATE TABLE public.billium_execution_operation_revision (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                operation_ref text NOT NULL,
                revision bigint NOT NULL,
                material_digest text NOT NULL,
                requested_minor bigint NOT NULL,
                currency text NOT NULL,
                observed jsonb NOT NULL,
                last_consistent_applied jsonb NOT NULL,
                financial_hold jsonb NOT NULL,
                exposure jsonb NOT NULL,
                evidence_refs jsonb NOT NULL,
                receipt_ref text NOT NULL,
                occurred_at timestamptz NOT NULL,
                UNIQUE(installation_ref,seller_ref,environment,operation_ref,revision),
                CHECK(revision>0 AND requested_minor>0),
                CHECK(length(operation_ref) BETWEEN 1 AND 200),
                CHECK(length(material_digest) BETWEEN 1 AND 200),
                CHECK(length(currency) BETWEEN 1 AND 200),
                CHECK(length(receipt_ref) BETWEEN 1 AND 200),
                CONSTRAINT ex_operation_revision_operation_fk FOREIGN KEY (installation_ref,seller_ref,environment,operation_ref) REFERENCES public.billium_execution_operation(installation_ref,seller_ref,environment,operation_ref)
            );

            CREATE TRIGGER billium_execution_operation_revision_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_operation_revision FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();

            CREATE INDEX billium_execution_revision_reduction
                ON public.billium_execution_operation_revision
                    (installation_ref,seller_ref,environment,operation_ref,receipt_ref,revision DESC);

            CREATE TABLE public.billium_execution_capacity (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                root_operation_ref text NOT NULL,
                currency text NOT NULL,
                authorized_limit_minor bigint NOT NULL DEFAULT 0,
                confirmed_capture_minor bigint NOT NULL DEFAULT 0,
                confirmed_refund_minor bigint NOT NULL DEFAULT 0,
                released_authorization_minor bigint NOT NULL DEFAULT 0,
                held_capture_minor bigint NOT NULL DEFAULT 0,
                held_void_minor bigint NOT NULL DEFAULT 0,
                held_refund_minor bigint NOT NULL DEFAULT 0,
                financial_hold boolean NOT NULL DEFAULT false,
                version bigint NOT NULL DEFAULT 0,
                UNIQUE(installation_ref,seller_ref,environment,root_operation_ref),
                CHECK(authorized_limit_minor>=0 AND confirmed_capture_minor>=0 AND confirmed_refund_minor>=0 AND released_authorization_minor>=0 AND held_capture_minor>=0 AND held_void_minor>=0 AND held_refund_minor>=0 AND version>=0),
                CHECK(length(root_operation_ref) BETWEEN 1 AND 200),
                CHECK(length(currency) BETWEEN 1 AND 200),
                CONSTRAINT ex_capacity_operation_fk FOREIGN KEY (installation_ref,seller_ref,environment,root_operation_ref) REFERENCES public.billium_execution_operation(installation_ref,seller_ref,environment,operation_ref),
                CONSTRAINT ex_capacity_unexpected_hold CHECK
                    (financial_hold OR (confirmed_capture_minor::numeric+released_authorization_minor<=authorized_limit_minor
                    AND confirmed_refund_minor<=confirmed_capture_minor))
            );

            CREATE TRIGGER billium_execution_capacity_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_capacity FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('authorized_limit_minor','confirmed_capture_minor','confirmed_refund_minor','released_authorization_minor','held_capture_minor','held_void_minor','held_refund_minor','financial_hold','version');

            CREATE TABLE public.billium_execution_reservation (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                reservation_ref text NOT NULL,
                root_operation_ref text NOT NULL,
                operation_ref text NOT NULL,
                kind text NOT NULL,
                requested_minor bigint NOT NULL,
                currency text NOT NULL,
                state text NOT NULL,
                consumed_minor bigint NOT NULL DEFAULT 0,
                release_evidence_ref text,
                version bigint NOT NULL DEFAULT 0,
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE(installation_ref,seller_ref,environment,reservation_ref),
                UNIQUE(installation_ref,seller_ref,environment,operation_ref),
                CHECK(kind IN ('capture','void_authorization','refund')),
                CHECK(requested_minor>0 AND consumed_minor>=0 AND consumed_minor<=requested_minor AND version>=0),
                CHECK(state IN ('held','consumed','released')),
                CHECK(state<>'released' OR release_evidence_ref IS NOT NULL),
                CHECK(length(reservation_ref) BETWEEN 1 AND 200),
                CHECK(length(root_operation_ref) BETWEEN 1 AND 200),
                CHECK(length(operation_ref) BETWEEN 1 AND 200),
                CHECK(length(kind) BETWEEN 1 AND 200),
                CHECK(length(currency) BETWEEN 1 AND 200),
                CHECK(length(state) BETWEEN 1 AND 200),
                CONSTRAINT ex_reservation_operation_fk FOREIGN KEY (installation_ref,seller_ref,environment,operation_ref) REFERENCES public.billium_execution_operation(installation_ref,seller_ref,environment,operation_ref)
            );

            CREATE TRIGGER billium_execution_reservation_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_reservation FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('state','consumed_minor','release_evidence_ref','version');

            CREATE TABLE public.billium_execution_attempt (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                attempt_ref text NOT NULL,
                operation_ref text NOT NULL,
                provider_reference text NOT NULL,
                provider_id text NOT NULL,
                merchant_account_ref text NOT NULL,
                state text NOT NULL DEFAULT 'admitted',
                lease_owner text,
                lease_fence bigint NOT NULL DEFAULT 0,
                lease_expires_at timestamptz,
                session_epoch bigint,
                owner_session_ref text,
                dispatch_ticket_ref text,
                request_digest text NOT NULL,
                started_at timestamptz,
                request_deadline_at timestamptz,
                finish_evidence_ref text,
                collection_dispatch_permit_ref text,
                UNIQUE(installation_ref,seller_ref,environment,attempt_ref),
                UNIQUE(installation_ref,seller_ref,environment,operation_ref),
                CHECK(state IN ('admitted','dispatching','pending','unknown','succeeded','failed','cancelled')),
                CHECK(lease_fence>=0),
                CHECK(length(attempt_ref) BETWEEN 1 AND 200),
                CHECK(length(operation_ref) BETWEEN 1 AND 200),
                CHECK(length(provider_reference) BETWEEN 1 AND 200),
                CHECK(length(provider_id) BETWEEN 1 AND 200),
                CHECK(length(merchant_account_ref) BETWEEN 1 AND 200),
                CHECK(length(state) BETWEEN 1 AND 200),
                CHECK(length(request_digest) BETWEEN 1 AND 200),
                CONSTRAINT ex_attempt_operation_fk FOREIGN KEY (installation_ref,seller_ref,environment,operation_ref) REFERENCES public.billium_execution_operation(installation_ref,seller_ref,environment,operation_ref),
                CONSTRAINT ex_collection_dispatch_permit_length
                    CHECK (length(collection_dispatch_permit_ref) BETWEEN 1 AND 200)
            );

            CREATE UNIQUE INDEX billium_execution_provider_reference ON public.billium_execution_attempt(installation_ref,environment,provider_id,merchant_account_ref,provider_reference);

            CREATE INDEX billium_execution_attempt_lookup ON public.billium_execution_attempt(installation_ref,seller_ref,environment,state,lease_expires_at,id);

            CREATE TABLE public.billium_execution_provider_fact (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                fact_ref text NOT NULL,
                operation_ref text NOT NULL,
                provider_identity_scope text NOT NULL,
                fact_identity text NOT NULL,
                evidence_mode text NOT NULL,
                material_digest text NOT NULL,
                normalized_snapshot jsonb NOT NULL,
                evidence_refs jsonb NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE(installation_ref,seller_ref,environment,fact_ref),
                UNIQUE(installation_ref,seller_ref,environment,provider_identity_scope,fact_identity,material_digest),
                CHECK(evidence_mode IN ('cumulative_snapshot','stable_effect_id')),
                CHECK(length(fact_ref) BETWEEN 1 AND 200),
                CHECK(length(operation_ref) BETWEEN 1 AND 200),
                CHECK(length(provider_identity_scope) BETWEEN 1 AND 200),
                CHECK(length(fact_identity) BETWEEN 1 AND 200),
                CHECK(length(evidence_mode) BETWEEN 1 AND 200),
                CHECK(length(material_digest) BETWEEN 1 AND 200),
                CONSTRAINT ex_provider_fact_operation_fk FOREIGN KEY (installation_ref,seller_ref,environment,operation_ref) REFERENCES public.billium_execution_operation(installation_ref,seller_ref,environment,operation_ref)
            );

            CREATE TRIGGER billium_execution_provider_fact_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_provider_fact FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();

            CREATE INDEX billium_execution_provider_fact_lookup ON public.billium_execution_provider_fact(installation_ref,seller_ref,environment,operation_ref,created_at,id);

            CREATE UNIQUE INDEX billium_execution_qualified_provider_fact ON public.billium_execution_provider_fact(provider_identity_scope,fact_identity)
                WHERE normalized_snapshot->>'bindingDigest' IS NOT NULL AND normalized_snapshot->>'operationAmount' IS NOT NULL;

            CREATE UNIQUE INDEX billium_execution_qualified_observation_identity ON public.billium_execution_provider_fact(provider_identity_scope,fact_identity)
                WHERE normalized_snapshot->>'bindingDigest' IS NOT NULL AND (normalized_snapshot->>'operationAmount' IS NOT NULL OR
                    (normalized_snapshot->>'providerReference' IS NOT NULL AND normalized_snapshot->>'normalizedStatus' IN ('pending','unknown')));

            CREATE TABLE public.billium_execution_discrepancy (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                discrepancy_ref text NOT NULL,
                root_operation_ref text NOT NULL,
                operation_ref text,
                kind text NOT NULL,
                evidence_refs jsonb NOT NULL,
                observed jsonb NOT NULL,
                last_consistent_applied jsonb NOT NULL,
                exposure jsonb NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                correction_of_ref text,
                UNIQUE(installation_ref,seller_ref,environment,discrepancy_ref),
                CHECK(length(discrepancy_ref) BETWEEN 1 AND 200),
                CHECK(length(root_operation_ref) BETWEEN 1 AND 200),
                CHECK(length(kind) BETWEEN 1 AND 200)
            );

            CREATE TRIGGER billium_execution_discrepancy_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_discrepancy FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();

            CREATE INDEX billium_execution_discrepancy_lookup ON public.billium_execution_discrepancy(installation_ref,seller_ref,environment,root_operation_ref,created_at,id);

            CREATE TABLE public.billium_execution_observation_partition (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                source_authority text NOT NULL,
                source_lineage text NOT NULL,
                root_operation_ref text NOT NULL,
                partition_ref text NOT NULL,
                last_sequence bigint NOT NULL DEFAULT 0,
                UNIQUE(installation_ref,seller_ref,environment,source_authority,source_lineage,root_operation_ref),
                UNIQUE(installation_ref,seller_ref,environment,source_authority,source_lineage,partition_ref),
                CHECK(last_sequence>=0),
                CHECK(length(source_authority) BETWEEN 1 AND 200),
                CHECK(length(source_lineage) BETWEEN 1 AND 200),
                CHECK(length(root_operation_ref) BETWEEN 1 AND 200),
                CHECK(length(partition_ref) BETWEEN 1 AND 200)
            );

            CREATE TRIGGER billium_execution_observation_partition_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_observation_partition FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('last_sequence');

            CREATE INDEX billium_execution_partition_root_idx ON public.billium_execution_observation_partition (installation_ref, seller_ref, environment, root_operation_ref, id);

            CREATE TABLE public.billium_execution_observation_emission (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                emission_ref text NOT NULL,
                source_authority text NOT NULL,
                source_lineage text NOT NULL,
                partition_ref text NOT NULL,
                source_sequence bigint NOT NULL,
                root_operation_ref text NOT NULL,
                operation_ref text NOT NULL,
                operation_revision bigint NOT NULL,
                command_ref text NOT NULL,
                event_id text NOT NULL,
                payload_digest text NOT NULL,
                payload jsonb NOT NULL,
                command_row_id bigint,
                command_schema text NOT NULL DEFAULT 'payment.observation.v1',
                created_xid xid8 NOT NULL DEFAULT pg_current_xact_id(),
                UNIQUE(installation_ref,seller_ref,environment,emission_ref),
                UNIQUE(installation_ref,seller_ref,environment,source_authority,source_lineage,partition_ref,source_sequence),
                UNIQUE(installation_ref,seller_ref,environment,operation_ref,operation_revision,source_lineage,command_schema),
                CHECK(source_sequence>0 AND operation_revision>0),
                CHECK(command_schema='payment.observation.v1'),
                CHECK(length(emission_ref) BETWEEN 1 AND 200),
                CHECK(length(source_authority) BETWEEN 1 AND 200),
                CHECK(length(source_lineage) BETWEEN 1 AND 200),
                CHECK(length(partition_ref) BETWEEN 1 AND 200),
                CHECK(length(root_operation_ref) BETWEEN 1 AND 200),
                CHECK(length(operation_ref) BETWEEN 1 AND 200),
                CHECK(length(command_ref) BETWEEN 1 AND 200),
                CHECK(length(event_id) BETWEEN 1 AND 200),
                CHECK(length(payload_digest) BETWEEN 1 AND 200),
                CHECK(length(command_schema) BETWEEN 1 AND 200)
            );

            CREATE TRIGGER billium_execution_observation_emission_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_observation_emission FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('command_row_id');

            CREATE UNIQUE INDEX billium_execution_command_ref ON public.billium_execution_observation_emission(command_ref);

            CREATE UNIQUE INDEX billium_execution_event_id ON public.billium_execution_observation_emission(event_id);

            CREATE TABLE public.billium_execution_authorization (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                authorization_ref text NOT NULL,
                root_operation_ref text NOT NULL,
                authorized_minor bigint NOT NULL,
                released_minor bigint NOT NULL,
                currency text NOT NULL,
                provider_expires_at timestamptz,
                expiry_evidence_ref text,
                state text NOT NULL,
                revision bigint NOT NULL,
                release_evidence_ref text,
                UNIQUE(installation_ref,seller_ref,environment,authorization_ref),
                UNIQUE(installation_ref,seller_ref,environment,root_operation_ref),
                CHECK(authorized_minor>=0 AND released_minor>=0 AND revision>=0),
                CHECK(state IN ('authorized','partially_captured','captured','voided','expired','pending','unknown')),
                CHECK(length(authorization_ref) BETWEEN 1 AND 200),
                CHECK(length(root_operation_ref) BETWEEN 1 AND 200),
                CHECK(length(currency) BETWEEN 1 AND 200),
                CHECK(length(state) BETWEEN 1 AND 200),
                CONSTRAINT ex_authorization_operation_fk FOREIGN KEY (installation_ref,seller_ref,environment,root_operation_ref) REFERENCES public.billium_execution_operation(installation_ref,seller_ref,environment,operation_ref),
                CONSTRAINT ex_authorization_release_basis
                    CHECK (released_minor=0 OR release_evidence_ref IS NOT NULL OR expiry_evidence_ref IS NOT NULL),
                CONSTRAINT ex_authorization_expiry_pair
                    CHECK ((provider_expires_at IS NULL)=(expiry_evidence_ref IS NULL)),
                CONSTRAINT ex_authorization_release_reference
                    CHECK (release_evidence_ref IS NULL OR length(release_evidence_ref) BETWEEN 1 AND 200)
            );

            CREATE TABLE public.billium_execution_reconciliation_run (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                run_ref text NOT NULL,
                merchant_account_ref text NOT NULL,
                recovery_horizon timestamptz NOT NULL,
                inventory_cursor text,
                completeness_watermark text,
                inventory_digest text,
                manifest_digest text,
                state text NOT NULL,
                unresolved jsonb NOT NULL,
                started_at timestamptz NOT NULL,
                completed_at timestamptz,
                UNIQUE(installation_ref,seller_ref,environment,run_ref),
                CHECK(state IN ('running','incomplete','complete','failed','parked')),
                CHECK(state<>'complete' OR (completeness_watermark IS NOT NULL AND inventory_digest IS NOT NULL AND manifest_digest IS NOT NULL AND completed_at IS NOT NULL)),
                CHECK(length(run_ref) BETWEEN 1 AND 200),
                CHECK(length(merchant_account_ref) BETWEEN 1 AND 200),
                CHECK(length(state) BETWEEN 1 AND 200)
            );

            CREATE TRIGGER billium_execution_reconciliation_run_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_reconciliation_run FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('inventory_cursor','completeness_watermark','inventory_digest','manifest_digest','state','unresolved','completed_at');

            CREATE TABLE public.billium_execution_repair_receipt (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                repair_ref text NOT NULL,
                root_operation_ref text NOT NULL,
                authorization_ref text NOT NULL,
                reason text NOT NULL,
                evidence_refs jsonb NOT NULL,
                before_digest text NOT NULL,
                after_digest text NOT NULL,
                correction_refs jsonb NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                action_kind text NOT NULL,
                intent_owner_namespace text NOT NULL,
                UNIQUE(installation_ref,seller_ref,environment,repair_ref),
                CHECK(length(repair_ref) BETWEEN 1 AND 200),
                CHECK(length(root_operation_ref) BETWEEN 1 AND 200),
                CHECK(length(authorization_ref) BETWEEN 1 AND 200),
                CHECK(length(reason) BETWEEN 1 AND 512),
                CHECK(length(before_digest) BETWEEN 1 AND 200),
                CHECK(length(after_digest) BETWEEN 1 AND 200),
                CHECK(length(action_kind) BETWEEN 1 AND 200),
                CHECK(length(intent_owner_namespace) BETWEEN 1 AND 200)
            );

            CREATE TRIGGER billium_execution_repair_receipt_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_repair_receipt FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();

            CREATE TABLE public.billium_execution_dispatch_work (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                work_ref text NOT NULL,
                operation_ref text NOT NULL,
                attempt_ref text NOT NULL,
                handler_version text NOT NULL,
                state text NOT NULL DEFAULT 'due',
                due_at timestamptz DEFAULT now(),
                lease_owner text,
                lease_fence bigint NOT NULL DEFAULT 0,
                lease_expires_at timestamptz,
                attempt_count bigint NOT NULL DEFAULT 0,
                last_error_code text,
                last_error_at timestamptz,
                UNIQUE(installation_ref,seller_ref,environment,work_ref),
                UNIQUE(installation_ref,seller_ref,environment,attempt_ref,handler_version),
                CHECK(state IN ('due','leased','processed','parked')),
                CHECK(lease_fence>=0 AND attempt_count>=0),
                CHECK(length(work_ref) BETWEEN 1 AND 200),
                CHECK(length(operation_ref) BETWEEN 1 AND 200),
                CHECK(length(attempt_ref) BETWEEN 1 AND 200),
                CHECK(length(handler_version) BETWEEN 1 AND 200),
                CHECK(length(state) BETWEEN 1 AND 200),
                CONSTRAINT ex_dispatch_work_attempt_fk FOREIGN KEY (installation_ref,seller_ref,environment,attempt_ref) REFERENCES public.billium_execution_attempt(installation_ref,seller_ref,environment,attempt_ref),
                CONSTRAINT ex_dispatch_due_state
                    CHECK ((state IN ('processed','parked')) = (due_at IS NULL) AND (due_at IS NULL OR isfinite(due_at)))
            );

            CREATE TRIGGER billium_execution_dispatch_work_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_dispatch_work FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('state','due_at','lease_owner','lease_fence','lease_expires_at','attempt_count','last_error_code','last_error_at');

            CREATE INDEX billium_execution_dispatch_work_lookup ON public.billium_execution_dispatch_work(installation_ref,seller_ref,environment,state,due_at,id);

            CREATE UNIQUE INDEX billium_execution_dispatch_one_work
                ON public.billium_execution_dispatch_work(installation_ref,seller_ref,environment,attempt_ref);

            CREATE INDEX billium_execution_recovery_queue_clock
                ON public.billium_execution_dispatch_work(installation_ref,seller_ref,environment,
                    (CASE WHEN state='leased' THEN lease_expires_at ELSE due_at END),id)
                WHERE state IN ('due','leased');

            CREATE TABLE public.billium_execution_provider_evidence (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                evidence_ref text NOT NULL,
                provider_id text NOT NULL,
                merchant_account_ref text NOT NULL,
                provider_event_id text,
                payload_digest text NOT NULL,
                signature_verdict text NOT NULL,
                handler_contract_version text NOT NULL,
                received_at timestamptz NOT NULL,
                sanitized_payload jsonb NOT NULL,
                secure_payload_ref text,
                raw_payload bytea,
                intent_owner_namespace text NOT NULL,
                UNIQUE(installation_ref,seller_ref,environment,evidence_ref),
                CHECK(length(evidence_ref) BETWEEN 1 AND 200),
                CHECK(length(provider_id) BETWEEN 1 AND 200),
                CHECK(length(merchant_account_ref) BETWEEN 1 AND 200),
                CHECK(length(payload_digest) BETWEEN 1 AND 200),
                CHECK(length(signature_verdict) BETWEEN 1 AND 200),
                CHECK(length(handler_contract_version) BETWEEN 1 AND 200),
                CHECK(length(intent_owner_namespace) BETWEEN 1 AND 200),
                CONSTRAINT ex_evidence_secure_custody
                    CHECK (secure_payload_ref IS NOT NULL AND length(secure_payload_ref) BETWEEN 1 AND 200 AND raw_payload IS NULL
                    AND octet_length(sanitized_payload::text)<=65536
                    AND sanitized_payload::text !~* '"(pan|cvc|cvv2?|password|secret|access_?token|refresh_?token|provider_?token|authorization|api_?key|client_?secret|card_?cvv|card_?number)"[[:space:]]*:')
            );

            CREATE TRIGGER billium_execution_provider_evidence_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_provider_evidence FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();

            CREATE INDEX billium_execution_provider_evidence_lookup ON public.billium_execution_provider_evidence(installation_ref,seller_ref,environment,provider_id,merchant_account_ref,provider_event_id);

            CREATE TABLE public.billium_execution_provider_work (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                work_ref text NOT NULL,
                evidence_ref text NOT NULL,
                handler_contract_version text NOT NULL,
                state text NOT NULL DEFAULT 'due',
                attempt_count bigint NOT NULL DEFAULT 0,
                due_at timestamptz NOT NULL DEFAULT now(),
                lease_owner text,
                lease_fence bigint NOT NULL DEFAULT 0,
                lease_expires_at timestamptz,
                last_error_code text,
                last_error_at timestamptz,
                result_ref text,
                operation_hint text,
                UNIQUE(installation_ref,seller_ref,environment,work_ref),
                UNIQUE(installation_ref,seller_ref,environment,evidence_ref,handler_contract_version),
                CHECK(state IN ('due','leased','processed','parked','quarantined')),
                CHECK(lease_fence>=0 AND attempt_count>=0),
                CHECK(state<>'leased' OR (lease_owner IS NOT NULL AND lease_expires_at IS NOT NULL)),
                CHECK(length(work_ref) BETWEEN 1 AND 200),
                CHECK(length(evidence_ref) BETWEEN 1 AND 200),
                CHECK(length(handler_contract_version) BETWEEN 1 AND 200),
                CHECK(length(state) BETWEEN 1 AND 200),
                CONSTRAINT ex_provider_work_provider_evidence_fk FOREIGN KEY (installation_ref,seller_ref,environment,evidence_ref) REFERENCES public.billium_execution_provider_evidence(installation_ref,seller_ref,environment,evidence_ref),
                CONSTRAINT ex_provider_work_hint_length
                    CHECK (length(operation_hint) BETWEEN 1 AND 200)
            );

            CREATE TRIGGER billium_execution_provider_work_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_provider_work FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('state','due_at','lease_owner','lease_fence','lease_expires_at','attempt_count','last_error_code','last_error_at','result_ref');

            CREATE INDEX billium_execution_provider_work_lookup ON public.billium_execution_provider_work(installation_ref,seller_ref,environment,state,due_at,id);

            CREATE TABLE public.billium_execution_merchant_lifecycle_event (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                event_ref text NOT NULL,
                revision_ref text NOT NULL,
                action text NOT NULL,
                purpose text NOT NULL,
                effective_at timestamptz NOT NULL,
                retention_until timestamptz,
                actor_ref text NOT NULL,
                reason text NOT NULL,
                evidence_ref text NOT NULL,
                UNIQUE(installation_ref,seller_ref,environment,event_ref),
                CHECK(action IN ('enable_new','stop_new','enable_recovery','disable_recovery')),
                CHECK(purpose IN ('new','lookup','refund','capture','void_authorization','reconciliation')),
                CHECK(length(event_ref) BETWEEN 1 AND 200),
                CHECK(length(revision_ref) BETWEEN 1 AND 200),
                CHECK(length(action) BETWEEN 1 AND 200),
                CHECK(length(purpose) BETWEEN 1 AND 200),
                CHECK(length(actor_ref) BETWEEN 1 AND 200),
                CHECK(length(reason) BETWEEN 1 AND 512),
                CHECK(length(evidence_ref) BETWEEN 1 AND 200),
                CONSTRAINT ex_merchant_lifecycle_event_merchant_revision_fk FOREIGN KEY (installation_ref,seller_ref,environment,revision_ref) REFERENCES public.billium_execution_merchant_revision(installation_ref,seller_ref,environment,revision_ref)
            );

            CREATE TRIGGER billium_execution_merchant_lifecycle_event_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_merchant_lifecycle_event FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();

            CREATE INDEX billium_execution_lifecycle_lookup ON public.billium_execution_merchant_lifecycle_event(installation_ref,seller_ref,environment,revision_ref,purpose,effective_at DESC,id DESC);

            CREATE TABLE public.billium_execution_merchant_credential_revision (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                credential_revision_ref text NOT NULL,
                revision_ref text NOT NULL,
                merchant_account_ref text NOT NULL,
                credential_ref text NOT NULL,
                credential_version_ref text NOT NULL,
                purpose text NOT NULL,
                valid_from timestamptz NOT NULL,
                valid_until timestamptz NOT NULL,
                identity_continuity_evidence_ref text NOT NULL,
                predecessor_ref text,
                UNIQUE(installation_ref,seller_ref,environment,credential_revision_ref),
                UNIQUE(installation_ref,seller_ref,environment,revision_ref,credential_version_ref,purpose),
                CHECK(valid_until>valid_from),
                CHECK(credential_ref ~ '^secret://[A-Za-z0-9][A-Za-z0-9/._:-]*$'),
                CHECK(purpose IN ('new','lookup','refund','capture','void_authorization','reconciliation')),
                CHECK(length(credential_revision_ref) BETWEEN 1 AND 200),
                CHECK(length(revision_ref) BETWEEN 1 AND 200),
                CHECK(length(merchant_account_ref) BETWEEN 1 AND 200),
                CHECK(length(credential_ref) BETWEEN 1 AND 200),
                CHECK(length(credential_version_ref) BETWEEN 1 AND 200),
                CHECK(length(purpose) BETWEEN 1 AND 200),
                CHECK(length(identity_continuity_evidence_ref) BETWEEN 1 AND 200),
                CONSTRAINT ex_merchant_credential_revision_merchant_revision_fk FOREIGN KEY (installation_ref,seller_ref,environment,revision_ref) REFERENCES public.billium_execution_merchant_revision(installation_ref,seller_ref,environment,revision_ref)
            );

            CREATE TRIGGER billium_execution_merchant_credential_revision_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_merchant_credential_revision FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();

            CREATE INDEX billium_execution_credential_lookup ON public.billium_execution_merchant_credential_revision(installation_ref,seller_ref,environment,revision_ref,purpose,valid_from,id);

            CREATE OR REPLACE FUNCTION public.billium_execution_reservation_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            DECLARE c public.billium_execution_capacity; o public.billium_execution_operation; held numeric;
            BEGIN
             SELECT * INTO c FROM public.billium_execution_capacity WHERE installation_ref=NEW.installation_ref AND seller_ref=NEW.seller_ref AND environment=NEW.environment AND root_operation_ref=NEW.root_operation_ref FOR UPDATE;
             SELECT * INTO o FROM public.billium_execution_operation WHERE installation_ref=NEW.installation_ref AND seller_ref=NEW.seller_ref AND environment=NEW.environment AND operation_ref=NEW.operation_ref;
             IF c.id IS NULL OR o.id IS NULL OR o.root_operation_ref<>NEW.root_operation_ref OR o.kind<>NEW.kind OR o.currency<>NEW.currency OR c.currency<>NEW.currency OR o.requested_minor<>NEW.requested_minor THEN
              RAISE EXCEPTION 'billium: reservation scope/root/kind/currency mismatch' USING ERRCODE='23514'; END IF;
             IF TG_OP='INSERT' THEN
              IF NEW.state<>'held' OR NEW.consumed_minor<>0 THEN RAISE EXCEPTION 'billium: reservation requires an unsettled request' USING ERRCODE='23514'; END IF;
              -- A verified import reconstructs the original reservation after the irreversible, fenced dispatch.
              -- Cumulative evidence may already contain this child; attribution and settlement still require its own fact.
              IF o.operation_ref<>o.root_operation_ref AND o.state='unknown' AND o.revision=0 AND EXISTS (
                SELECT 1 FROM public.billium_execution_original_identity_import i
                JOIN public.billium_execution_attempt a ON (a.installation_ref,a.seller_ref,a.environment,a.operation_ref,a.attempt_ref)
                  = (i.installation_ref,i.seller_ref,i.environment,i.operation_ref,i.attempt_ref)
                JOIN public.billium_execution_namespace n ON (n.installation_ref,n.seller_ref,n.environment,n.intent_owner_namespace)
                  = (i.installation_ref,i.seller_ref,i.environment,i.intent_owner_namespace)
                WHERE (i.installation_ref,i.seller_ref,i.environment,i.intent_owner_namespace,i.operation_ref)
                  = (o.installation_ref,o.seller_ref,o.environment,o.intent_owner_namespace,o.operation_ref)
                  AND n.state IN ('closed','recovery_required') AND a.state='unknown' AND a.started_at IS NOT NULL
                  AND a.dispatch_ticket_ref IS NOT NULL AND a.lease_fence::numeric=i.original_fence::numeric+1
                  AND a.session_epoch>0 AND a.owner_session_ref IS NOT NULL AND a.lease_owner IS NULL AND a.lease_expires_at IS NULL
                  AND a.merchant_account_ref=o.merchant_account_ref AND NOT EXISTS (
                    SELECT 1 FROM public.billium_execution_dispatch_work w WHERE (w.installation_ref,w.seller_ref,w.environment,w.operation_ref)
                      = (o.installation_ref,o.seller_ref,o.environment,o.operation_ref))
              ) THEN RETURN NEW; END IF;
              IF c.financial_hold THEN RAISE EXCEPTION 'billium: reservation requires open capacity' USING ERRCODE='23514'; END IF;
              IF NEW.kind='refund' THEN
               SELECT coalesce(sum(requested_minor-consumed_minor),0) INTO held FROM public.billium_execution_reservation WHERE installation_ref=NEW.installation_ref AND seller_ref=NEW.seller_ref AND environment=NEW.environment AND root_operation_ref=NEW.root_operation_ref AND kind='refund' AND state='held';
               IF c.confirmed_refund_minor::numeric+held+NEW.requested_minor>c.confirmed_capture_minor THEN RAISE EXCEPTION 'billium: refund capacity exceeded' USING ERRCODE='23514'; END IF;
              ELSE
               SELECT coalesce(sum(requested_minor-consumed_minor),0) INTO held FROM public.billium_execution_reservation WHERE installation_ref=NEW.installation_ref AND seller_ref=NEW.seller_ref AND environment=NEW.environment AND root_operation_ref=NEW.root_operation_ref AND kind IN ('capture','void_authorization') AND state='held';
               IF c.confirmed_capture_minor::numeric+c.released_authorization_minor+held+NEW.requested_minor>c.authorized_limit_minor THEN RAISE EXCEPTION 'billium: authorization capacity exceeded' USING ERRCODE='23514'; END IF;
              END IF;
             ELSIF OLD.state<>'held' OR NEW.version<>OLD.version+1 OR NEW.state='held' THEN RAISE EXCEPTION 'billium: reservation terminal transition invalid' USING ERRCODE='23514'; END IF;
             RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_reservation_guard() FROM PUBLIC;

            CREATE TRIGGER billium_execution_reservation_guard BEFORE INSERT OR UPDATE ON public.billium_execution_reservation FOR EACH ROW EXECUTE FUNCTION public.billium_execution_reservation_guard();

            CREATE FUNCTION public.billium_execution_merchant_policy_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            DECLARE m public.billium_execution_merchant_revision; conflict_ref text;
            BEGIN
                SELECT * INTO m FROM public.billium_execution_merchant_revision
                WHERE installation_ref=NEW.installation_ref AND seller_ref=NEW.seller_ref
                    AND environment=NEW.environment AND revision_ref=NEW.revision_ref;
                IF m.id IS NULL THEN RAISE EXCEPTION 'billium: merchant revision absent' USING ERRCODE='23503'; END IF;
                IF TG_TABLE_NAME='billium_execution_merchant_credential_revision' THEN
                    IF NEW.merchant_account_ref<>m.merchant_account_ref OR NEW.identity_continuity_evidence_ref=''
                    THEN RAISE EXCEPTION 'billium: credential account continuity mismatch' USING ERRCODE='23514'; END IF;
                    IF NEW.predecessor_ref IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM public.billium_execution_merchant_credential_revision p
                        WHERE p.installation_ref=NEW.installation_ref AND p.seller_ref=NEW.seller_ref AND p.environment=NEW.environment
                            AND p.revision_ref=NEW.revision_ref AND p.purpose=NEW.purpose
                            AND p.credential_revision_ref=NEW.predecessor_ref AND p.merchant_account_ref=NEW.merchant_account_ref)
                    THEN RAISE EXCEPTION 'billium: credential predecessor substitution' USING ERRCODE='23514'; END IF;
                ELSE
                    IF (NEW.purpose='new') <> (NEW.action IN ('enable_new','stop_new'))
                       OR (NEW.action='enable_recovery' AND (NEW.retention_until IS NULL OR NEW.retention_until<=NEW.effective_at))
                    THEN RAISE EXCEPTION 'billium: lifecycle action/purpose/horizon mismatch' USING ERRCODE='23514'; END IF;
                    -- The transaction lock serializes competing active revisions, including raw SQL writers.
                    PERFORM pg_advisory_xact_lock(hashtextextended(NEW.installation_ref||'/'||NEW.seller_ref||'/'||NEW.environment||'/'||m.webhook_route_ref,7419));
                    IF NEW.action='enable_new' THEN
                        SELECT r.revision_ref INTO conflict_ref FROM public.billium_execution_merchant_revision r
                        JOIN LATERAL (SELECT e.action FROM public.billium_execution_merchant_lifecycle_event e
                            WHERE e.installation_ref=r.installation_ref AND e.seller_ref=r.seller_ref AND e.environment=r.environment
                                AND e.revision_ref=r.revision_ref AND e.purpose='new' AND e.effective_at<=NEW.effective_at
                            ORDER BY e.effective_at DESC,e.id DESC LIMIT 1) latest ON true
                        WHERE r.installation_ref=m.installation_ref AND r.seller_ref=m.seller_ref AND r.environment=m.environment
                            AND r.webhook_route_ref=m.webhook_route_ref AND r.revision_ref<>m.revision_ref
                            AND r.valid_from<=NEW.effective_at AND (r.valid_until IS NULL OR r.valid_until>NEW.effective_at)
                            AND latest.action='enable_new' LIMIT 1;
                        IF conflict_ref IS NOT NULL THEN RAISE EXCEPTION 'billium: competing active merchant revision' USING ERRCODE='23514'; END IF;
                    END IF;
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_merchant_policy_guard() FROM PUBLIC;

            CREATE TRIGGER billium_execution_merchant_policy_guard BEFORE INSERT ON public.billium_execution_merchant_lifecycle_event
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_merchant_policy_guard();

            CREATE TRIGGER billium_execution_credential_policy_guard BEFORE INSERT ON public.billium_execution_merchant_credential_revision
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_merchant_policy_guard();

            CREATE FUNCTION public.billium_execution_operation_binding_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            DECLARE r public.billium_execution_operation;
            BEGIN
                IF (NEW.binding->>'merchantAccountRef',NEW.binding->>'merchantRevisionRef',NEW.binding->>'driverId',
                    NEW.binding->>'driverContractVersion',NEW.binding->>'capabilityDigest',NEW.binding->>'rootOperationRef',
                    NEW.binding->'scope'->>'installationRef',NEW.binding->'scope'->>'sellerRef',NEW.binding->'scope'->>'environment',
                    NEW.binding->'scope'->>'intentOwnerNamespace') IS DISTINCT FROM
                    (NEW.merchant_account_ref,NEW.merchant_revision_ref,NEW.driver_id,NEW.driver_contract_version,NEW.capability_digest,
                    NEW.root_operation_ref,NEW.installation_ref,NEW.seller_ref,NEW.environment,NEW.intent_owner_namespace)
                THEN RAISE EXCEPTION 'billium: binding relational identity mismatch' USING ERRCODE='23514'; END IF;
                IF NEW.root_operation_ref<>NEW.operation_ref THEN
                    SELECT * INTO r FROM public.billium_execution_operation WHERE installation_ref=NEW.installation_ref
                        AND seller_ref=NEW.seller_ref AND environment=NEW.environment AND operation_ref=NEW.root_operation_ref;
                    IF r.id IS NULL OR r.operation_ref<>r.root_operation_ref OR NEW.binding<>r.binding OR NEW.currency<>r.currency
                    THEN RAISE EXCEPTION 'billium: child historical binding mismatch' USING ERRCODE='23514'; END IF;
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_operation_binding_guard() FROM PUBLIC;

            CREATE TRIGGER billium_execution_operation_binding_guard BEFORE INSERT ON public.billium_execution_operation
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_operation_binding_guard();

            CREATE FUNCTION public.billium_execution_credential_identity_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            DECLARE m public.billium_execution_merchant_revision; p public.billium_execution_merchant_credential_revision;
            BEGIN
                SELECT * INTO m FROM public.billium_execution_merchant_revision WHERE installation_ref=NEW.installation_ref
                    AND seller_ref=NEW.seller_ref AND environment=NEW.environment AND revision_ref=NEW.revision_ref;
                IF m.id IS NULL OR NEW.merchant_account_ref<>m.merchant_account_ref THEN
                    RAISE EXCEPTION 'billium: credential account substitution' USING ERRCODE='23514'; END IF;
                IF NEW.predecessor_ref IS NOT NULL THEN
                    SELECT * INTO p FROM public.billium_execution_merchant_credential_revision WHERE installation_ref=NEW.installation_ref
                        AND seller_ref=NEW.seller_ref AND environment=NEW.environment AND credential_revision_ref=NEW.predecessor_ref;
                    IF p.id IS NULL OR (p.revision_ref,p.merchant_account_ref,p.purpose) IS DISTINCT FROM (NEW.revision_ref,NEW.merchant_account_ref,NEW.purpose) THEN
                        RAISE EXCEPTION 'billium: credential continuity crosses original account/action' USING ERRCODE='23514'; END IF;
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_credential_identity_guard() FROM PUBLIC;

            CREATE TRIGGER billium_execution_credential_identity_guard BEFORE INSERT ON public.billium_execution_merchant_credential_revision
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_credential_identity_guard();

            CREATE FUNCTION public.billium_execution_attempt_identity_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            DECLARE o public.billium_execution_operation; m public.billium_execution_merchant_revision;
            BEGIN
                SELECT * INTO o FROM public.billium_execution_operation WHERE installation_ref=NEW.installation_ref
                    AND seller_ref=NEW.seller_ref AND environment=NEW.environment AND operation_ref=NEW.operation_ref;
                SELECT * INTO m FROM public.billium_execution_merchant_revision WHERE installation_ref=o.installation_ref
                    AND seller_ref=o.seller_ref AND environment=o.environment AND revision_ref=o.merchant_revision_ref;
                IF o.id IS NULL OR m.id IS NULL OR (NEW.provider_id,NEW.merchant_account_ref) IS DISTINCT FROM (m.provider_id,m.merchant_account_ref)
                THEN RAISE EXCEPTION 'billium: attempt original account/provider mismatch' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND (NEW.lease_fence<OLD.lease_fence OR NEW.lease_fence>OLD.lease_fence+1 OR
                    (NEW.state='dispatching' AND OLD.state<>'dispatching' AND (OLD.state<>'admitted' OR NEW.session_epoch IS NULL OR NEW.owner_session_ref IS NULL OR NEW.dispatch_ticket_ref IS NULL)))
                THEN RAISE EXCEPTION 'billium: invalid dispatch fence/session transition' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_attempt_identity_guard() FROM PUBLIC;

            CREATE TRIGGER billium_execution_attempt_identity_guard BEFORE INSERT OR UPDATE ON public.billium_execution_attempt
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_attempt_identity_guard();

            CREATE TRIGGER billium_execution_attempt_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_attempt
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('state','lease_owner','lease_fence','lease_expires_at',
                    'session_epoch','owner_session_ref','dispatch_ticket_ref','started_at','request_deadline_at','finish_evidence_ref','collection_dispatch_permit_ref');

            CREATE FUNCTION public.billium_execution_capability_identity_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            BEGIN
                IF (NEW.body->>'snapshotRef',NEW.body->>'merchantAccountRef',NEW.body->>'driverId',NEW.body->>'driverContractVersion',
                    NEW.body->>'capabilityVersion',NEW.body->>'qualificationMode',NEW.body->>'qualificationEvidenceRef',NEW.body->>'digest',
                    NEW.body->'details'->>'captureMode') IS DISTINCT FROM
                    (NEW.snapshot_ref,NEW.merchant_account_ref,NEW.driver_id,NEW.driver_contract_version,NEW.capability_version,
                    NEW.qualification_mode,NEW.qualification_evidence_ref,NEW.digest,NEW.capture_mode)
                    OR NEW.body->'supportedEffects' IS DISTINCT FROM to_jsonb(NEW.supported_effects)
                    OR NEW.body->'currencies' IS DISTINCT FROM to_jsonb(NEW.currencies)
                    OR NEW.body->>'partialRefund' IS DISTINCT FROM NEW.partial_refund::text
                    OR NEW.body->'details'->>'multipleRefund' IS DISTINCT FROM NEW.multiple_refund::text
                    OR NEW.body->'details'->>'lookupByProviderId' IS DISTINCT FROM NEW.lookup_by_provider_id::text
                    OR NEW.body->>'lookupByReference' IS DISTINCT FROM NEW.lookup_by_reference::text
                    OR NEW.body->>'inventoryComplete' IS DISTINCT FROM NEW.inventory_complete::text
                    OR NEW.body->>'absenceCertification' IS DISTINCT FROM NEW.absence_certification::text
                    OR NEW.body->'details'->>'idempotencyRetentionSeconds' IS DISTINCT FROM NEW.idempotency_retention_seconds::text
                    OR NEW.body->'details'->>'lookupRetentionSeconds' IS DISTINCT FROM NEW.lookup_retention_seconds::text
                THEN RAISE EXCEPTION 'billium: capability relational/body substitution' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_capability_identity_guard() FROM PUBLIC;

            CREATE TRIGGER billium_execution_capability_identity_guard BEFORE INSERT ON public.billium_execution_capability_snapshot
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_capability_identity_guard();

            CREATE FUNCTION public.billium_execution_emission_root_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            BEGIN
                IF TG_OP='UPDATE' AND OLD.created_xid<>pg_current_xact_id() THEN
                    RAISE EXCEPTION 'billium: emission mapping cannot change after root commit' USING ERRCODE='55000'; END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_emission_root_guard() FROM PUBLIC;

            CREATE TRIGGER billium_execution_emission_root_guard BEFORE UPDATE ON public.billium_execution_observation_emission
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_emission_root_guard();

            CREATE FUNCTION public.billium_execution_emission_complete_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            BEGIN
                IF EXISTS(SELECT 1 FROM public.billium_execution_observation_emission WHERE id=NEW.id AND command_row_id IS NULL) THEN
                    RAISE EXCEPTION 'billium: emission command row missing at root commit' USING ERRCODE='23514'; END IF;
                RETURN NULL;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_emission_complete_guard() FROM PUBLIC;

            CREATE CONSTRAINT TRIGGER billium_execution_emission_complete_guard AFTER INSERT OR UPDATE ON public.billium_execution_observation_emission
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.billium_execution_emission_complete_guard();

            CREATE OR REPLACE FUNCTION public.billium_execution_reservation_proof_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            BEGIN
                IF NEW.state='released' OR (NEW.state='consumed' AND NEW.consumed_minor<NEW.requested_minor) THEN
                    IF NEW.state='released' AND NEW.consumed_minor<>0 THEN
                        RAISE EXCEPTION 'billium: released reservation has a positive effect' USING ERRCODE='23514';
                    END IF;
                    PERFORM 1 FROM public.billium_execution_operation o
                    JOIN public.billium_execution_attempt a USING (installation_ref,seller_ref,environment,operation_ref)
                    JOIN public.billium_execution_provider_evidence e USING (installation_ref,seller_ref,environment,intent_owner_namespace)
                    WHERE o.installation_ref=NEW.installation_ref AND o.seller_ref=NEW.seller_ref AND o.environment=NEW.environment
                        AND o.operation_ref=NEW.operation_ref AND o.kind=NEW.kind AND o.root_operation_ref=NEW.root_operation_ref
                        AND o.requested_minor=NEW.requested_minor AND o.currency=NEW.currency AND e.evidence_ref=NEW.release_evidence_ref
                        AND e.provider_id=a.provider_id AND e.merchant_account_ref=a.merchant_account_ref
                        AND (
                            (NEW.state='released' AND EXISTS (SELECT 1 FROM public.billium_execution_certified_absence c
                                WHERE c.installation_ref=a.installation_ref AND c.seller_ref=a.seller_ref AND c.environment=a.environment
                                    AND c.attempt_ref=a.attempt_ref AND c.evidence_ref=e.evidence_ref))
                            OR EXISTS (SELECT 1 FROM public.billium_execution_provider_fact f
                                WHERE f.installation_ref=o.installation_ref AND f.seller_ref=o.seller_ref AND f.environment=o.environment
                                    AND f.operation_ref=o.operation_ref AND f.evidence_refs ? e.evidence_ref
                                    AND f.normalized_snapshot->>'providerReference'=a.provider_reference
                                    AND f.normalized_snapshot->>'merchantAccountRef'=a.merchant_account_ref
                                    AND f.normalized_snapshot->>'bindingDigest'=o.binding_digest
                                    AND f.normalized_snapshot->>'kind'=o.kind AND f.normalized_snapshot->>'currency'=o.currency
                                    AND f.normalized_snapshot#>>'{operationAmount,currency,code}'=o.currency
                                    AND f.normalized_snapshot#>>'{operationAmount,minor}'=NEW.consumed_minor::text
                                    AND ((NEW.state='released' AND f.normalized_snapshot->>'normalizedStatus' IN ('declined','failed','no_effect'))
                                      OR (NEW.state='consumed' AND f.normalized_snapshot->>'normalizedStatus'='succeeded'))));
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'billium: reservation requires original accepted terminal proof' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_reservation_proof_guard() FROM PUBLIC;

            CREATE TRIGGER billium_execution_reservation_proof_guard BEFORE UPDATE ON public.billium_execution_reservation
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_reservation_proof_guard();

            CREATE INDEX billium_execution_reservation_root_idx ON public.billium_execution_reservation (installation_ref, seller_ref, environment, root_operation_ref, id);

            CREATE OR REPLACE FUNCTION public.billium_execution_root_identity_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            DECLARE b public.billium_execution_business_binding;
            BEGIN
                IF NEW.operation_ref=NEW.root_operation_ref THEN
                    SELECT * INTO b FROM public.billium_execution_business_binding
                        WHERE business_identity_digest=public.billium_execution_business_identity_digest(NEW.intent_owner_namespace, NEW.environment, NEW.source_context, NEW.business_intent_ref, 'charge')
                        AND intent_owner_namespace=NEW.intent_owner_namespace AND environment=NEW.environment
                        AND source_context=NEW.source_context AND business_intent_ref=NEW.business_intent_ref AND operation_kind='charge';
                    IF b.id IS NULL OR b.tombstone_reason IS NOT NULL OR
                        (b.installation_ref,b.seller_ref,b.operation_ref,b.semantic_digest,b.binding) IS DISTINCT FROM
                        (NEW.installation_ref,NEW.seller_ref,NEW.operation_ref,NEW.economic_digest,NEW.binding) THEN
                        RAISE EXCEPTION 'billium: root operation requires original durable business identity' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_root_identity_guard() FROM PUBLIC;

            CREATE TRIGGER billium_execution_root_identity_guard BEFORE INSERT ON public.billium_execution_operation
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_root_identity_guard();

            CREATE INDEX billium_execution_operation_root_idx ON public.billium_execution_operation (installation_ref, seller_ref, environment, intent_owner_namespace, root_operation_ref, id);

            CREATE TABLE public.billium_execution_admission_conflict (
                audit_ref text PRIMARY KEY CHECK (audit_ref ~ '^conflict:[a-f0-9]{64}$'),
                installation_ref text NOT NULL CHECK (length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK (length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK (length(environment) BETWEEN 1 AND 200),
                intent_owner_namespace text NOT NULL CHECK (length(intent_owner_namespace) BETWEEN 1 AND 200),
                actor_ref text NOT NULL CHECK (length(actor_ref) BETWEEN 1 AND 200),
                command_kind text NOT NULL,
                command_key_digest text NOT NULL CHECK (command_key_digest ~ '^sha256:[a-f0-9]{64}$'),
                semantic_digest text NOT NULL CHECK (semantic_digest ~ '^sha256:[a-f0-9]{64}$'),
                original_operation_ref text CHECK (length(original_operation_ref) BETWEEN 1 AND 200),
                problem_code text NOT NULL CHECK (problem_code = 'billium.idempotency.conflict'),
                recorded_at timestamptz NOT NULL DEFAULT clock_timestamp()
            ,
                source_authority text,
                CONSTRAINT billium_execution_conflict_source
                    CHECK (source_authority IS NULL OR length(source_authority) BETWEEN 1 AND 200),
                CONSTRAINT ex_execution_conflict_command_kind
                    CHECK (command_kind IN ('payment.execute.v1','payment.authorize.v1','payment.capture.v1','payment.void_authorization.v1','payment.refund.v1')));

            CREATE TRIGGER billium_execution_admission_conflict_immutable BEFORE UPDATE OR DELETE
                ON public.billium_execution_admission_conflict FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();

            CREATE TRIGGER billium_execution_authorization_immutable BEFORE UPDATE OR DELETE
                ON public.billium_execution_authorization FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable
                    ('authorized_minor','released_minor','provider_expires_at','expiry_evidence_ref','release_evidence_ref','state','revision');

            CREATE INDEX billium_execution_admission_conflict_history
                ON public.billium_execution_admission_conflict
                    (installation_ref,seller_ref,environment,intent_owner_namespace,source_authority,
                     command_kind,command_key_digest,recorded_at DESC,audit_ref DESC)
                WHERE source_authority IS NOT NULL;

            CREATE OR REPLACE FUNCTION public.billium_lock_execution_merchant_revision(p_ids bigint[], p_seller text, p_environment text, p_shared boolean)
            RETURNS TABLE (lock_key bigint) LANGUAGE plpgsql STRICT SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE installation text := current_setting('billium.merchant_gate_installation', true);
            BEGIN
                IF p_shared THEN
                    RETURN QUERY SELECT r.id FROM public.billium_execution_merchant_revision r
                        WHERE r.id = ANY(p_ids) AND r.installation_ref = installation AND r.seller_ref = p_seller
                            AND r.environment = p_environment ORDER BY r.id FOR SHARE;
                ELSE
                    RETURN QUERY SELECT r.id FROM public.billium_execution_merchant_revision r
                        WHERE r.id = ANY(p_ids) AND r.installation_ref = installation AND r.seller_ref = p_seller
                            AND r.environment = p_environment ORDER BY r.id FOR UPDATE;
                END IF;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_lock_execution_merchant_revision(p_ids bigint[], p_seller text, p_environment text, p_shared boolean) FROM PUBLIC;

            -- The runtime can INSERT events directly. Those inserts must share the same barrier as the typed writers.
            CREATE OR REPLACE FUNCTION public.billium_execution_lock_merchant_writer() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                PERFORM r.id FROM public.billium_execution_merchant_revision r WHERE r.installation_ref = NEW.installation_ref
                    AND r.seller_ref = NEW.seller_ref AND r.environment = NEW.environment AND r.revision_ref = NEW.revision_ref FOR UPDATE;
                IF NOT FOUND THEN RAISE EXCEPTION 'billium: original merchant revision unavailable' USING ERRCODE = '23503'; END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_lock_merchant_writer() FROM PUBLIC;

            CREATE TRIGGER billium_execution_admission_writer_lock BEFORE INSERT ON public.billium_execution_merchant_lifecycle_event
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_lock_merchant_writer();

            CREATE TRIGGER billium_execution_admission_writer_lock BEFORE INSERT ON public.billium_execution_merchant_credential_revision
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_lock_merchant_writer();
            SQL);

        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_namespace');
        RuntimeGrants::grant('UPDATE (state, history_from, completeness_receipt_ref, generation)', 'public.billium_execution_namespace');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_capability_snapshot');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_merchant_revision');
        RuntimeGrants::grant('EXECUTE', 'FUNCTION public.billium_execution_business_identity_digest(text, text, text, text, text)');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_business_binding');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_operation');
        RuntimeGrants::grant('UPDATE (state, revision, economic_digest, authorization_ref, next_reconcile_at, last_reconciled_at, reconcile_attempts, reconcile_state)', 'public.billium_execution_operation');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_operation_revision');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_capacity');
        RuntimeGrants::grant('UPDATE (authorized_limit_minor, confirmed_capture_minor, confirmed_refund_minor, released_authorization_minor, held_capture_minor, held_void_minor, held_refund_minor, financial_hold, version)', 'public.billium_execution_capacity');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_reservation');
        RuntimeGrants::grant('UPDATE (state, consumed_minor, release_evidence_ref, version)', 'public.billium_execution_reservation');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_attempt');
        RuntimeGrants::grant('UPDATE (state, lease_owner, lease_fence, lease_expires_at, session_epoch, owner_session_ref, dispatch_ticket_ref, started_at, request_deadline_at, finish_evidence_ref, collection_dispatch_permit_ref)', 'public.billium_execution_attempt');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_provider_fact');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_discrepancy');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_observation_partition');
        RuntimeGrants::grant('UPDATE (last_sequence)', 'public.billium_execution_observation_partition');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_observation_emission');
        RuntimeGrants::grant('UPDATE (command_row_id)', 'public.billium_execution_observation_emission');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_authorization');
        RuntimeGrants::grant('UPDATE (authorized_minor, released_minor, provider_expires_at, expiry_evidence_ref, state, revision, release_evidence_ref)', 'public.billium_execution_authorization');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_reconciliation_run');
        RuntimeGrants::grant('UPDATE (inventory_cursor, completeness_watermark, inventory_digest, manifest_digest, state, unresolved, completed_at)', 'public.billium_execution_reconciliation_run');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_repair_receipt');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_dispatch_work');
        RuntimeGrants::grant('UPDATE (state, due_at, lease_owner, lease_fence, lease_expires_at, attempt_count, last_error_code, last_error_at)', 'public.billium_execution_dispatch_work');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_provider_evidence');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_provider_work');
        RuntimeGrants::grant('UPDATE (state, attempt_count, due_at, lease_owner, lease_fence, lease_expires_at, last_error_code, last_error_at, result_ref)', 'public.billium_execution_provider_work');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_merchant_lifecycle_event');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_merchant_credential_revision');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_admission_conflict');
        RuntimeGrants::grant('EXECUTE', 'FUNCTION public.billium_lock_execution_merchant_revision(p_ids bigint[], p_seller text, p_environment text, p_shared boolean)');
    }

    public function down(): never
    {
        throw new LogicException('Billium migrations are forward-only.');
    }
};
