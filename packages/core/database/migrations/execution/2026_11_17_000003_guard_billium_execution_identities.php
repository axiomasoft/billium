<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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

            ALTER TABLE public.billium_execution_capacity ADD CONSTRAINT ex_capacity_unexpected_hold CHECK
                (financial_hold OR (confirmed_capture_minor::numeric+released_authorization_minor<=authorized_limit_minor
                    AND confirmed_refund_minor<=confirmed_capture_minor)) NOT VALID;
            ALTER TABLE public.billium_execution_operation ADD CONSTRAINT ex_operation_currency_scale CHECK (currency_exponent=(CASE currency WHEN 'AED' THEN 2 WHEN 'AFN' THEN 2 WHEN 'ALL' THEN 2 WHEN 'AMD' THEN 2 WHEN 'AOA' THEN 2 WHEN 'ARS' THEN 2 WHEN 'AUD' THEN 2 WHEN 'AWG' THEN 2 WHEN 'AZN' THEN 2 WHEN 'BAM' THEN 2 WHEN 'BBD' THEN 2 WHEN 'BDT' THEN 2 WHEN 'BHD' THEN 3 WHEN 'BIF' THEN 0 WHEN 'BMD' THEN 2 WHEN 'BND' THEN 2 WHEN 'BOB' THEN 2 WHEN 'BOV' THEN 2 WHEN 'BRL' THEN 2 WHEN 'BSD' THEN 2 WHEN 'BTN' THEN 2 WHEN 'BWP' THEN 2 WHEN 'BYN' THEN 2 WHEN 'BZD' THEN 2 WHEN 'CAD' THEN 2 WHEN 'CDF' THEN 2 WHEN 'CHE' THEN 2 WHEN 'CHF' THEN 2 WHEN 'CHW' THEN 2 WHEN 'CLF' THEN 4 WHEN 'CLP' THEN 0 WHEN 'CNY' THEN 2 WHEN 'COP' THEN 2 WHEN 'COU' THEN 2 WHEN 'CRC' THEN 2 WHEN 'CUP' THEN 2 WHEN 'CVE' THEN 2 WHEN 'CZK' THEN 2 WHEN 'DJF' THEN 0 WHEN 'DKK' THEN 2 WHEN 'DOP' THEN 2 WHEN 'DZD' THEN 2 WHEN 'EGP' THEN 2 WHEN 'ERN' THEN 2 WHEN 'ETB' THEN 2 WHEN 'EUR' THEN 2 WHEN 'FJD' THEN 2 WHEN 'FKP' THEN 2 WHEN 'GBP' THEN 2 WHEN 'GEL' THEN 2 WHEN 'GHS' THEN 2 WHEN 'GIP' THEN 2 WHEN 'GMD' THEN 2 WHEN 'GNF' THEN 0 WHEN 'GTQ' THEN 2 WHEN 'GYD' THEN 2 WHEN 'HKD' THEN 2 WHEN 'HNL' THEN 2 WHEN 'HTG' THEN 2 WHEN 'HUF' THEN 2 WHEN 'IDR' THEN 2 WHEN 'ILS' THEN 2 WHEN 'INR' THEN 2 WHEN 'IQD' THEN 3 WHEN 'IRR' THEN 2 WHEN 'ISK' THEN 0 WHEN 'JMD' THEN 2 WHEN 'JOD' THEN 3 WHEN 'JPY' THEN 0 WHEN 'KES' THEN 2 WHEN 'KGS' THEN 2 WHEN 'KHR' THEN 2 WHEN 'KMF' THEN 0 WHEN 'KPW' THEN 2 WHEN 'KRW' THEN 0 WHEN 'KWD' THEN 3 WHEN 'KYD' THEN 2 WHEN 'KZT' THEN 2 WHEN 'LAK' THEN 2 WHEN 'LBP' THEN 2 WHEN 'LKR' THEN 2 WHEN 'LRD' THEN 2 WHEN 'LSL' THEN 2 WHEN 'LYD' THEN 3 WHEN 'MAD' THEN 2 WHEN 'MDL' THEN 2 WHEN 'MGA' THEN 2 WHEN 'MKD' THEN 2 WHEN 'MMK' THEN 2 WHEN 'MNT' THEN 2 WHEN 'MOP' THEN 2 WHEN 'MRU' THEN 2 WHEN 'MUR' THEN 2 WHEN 'MVR' THEN 2 WHEN 'MWK' THEN 2 WHEN 'MXN' THEN 2 WHEN 'MXV' THEN 2 WHEN 'MYR' THEN 2 WHEN 'MZN' THEN 2 WHEN 'NAD' THEN 2 WHEN 'NGN' THEN 2 WHEN 'NIO' THEN 2 WHEN 'NOK' THEN 2 WHEN 'NPR' THEN 2 WHEN 'NZD' THEN 2 WHEN 'OMR' THEN 3 WHEN 'PAB' THEN 2 WHEN 'PEN' THEN 2 WHEN 'PGK' THEN 2 WHEN 'PHP' THEN 2 WHEN 'PKR' THEN 2 WHEN 'PLN' THEN 2 WHEN 'PYG' THEN 0 WHEN 'QAR' THEN 2 WHEN 'RON' THEN 2 WHEN 'RSD' THEN 2 WHEN 'RUB' THEN 2 WHEN 'RWF' THEN 0 WHEN 'SAR' THEN 2 WHEN 'SBD' THEN 2 WHEN 'SCR' THEN 2 WHEN 'SDG' THEN 2 WHEN 'SEK' THEN 2 WHEN 'SGD' THEN 2 WHEN 'SHP' THEN 2 WHEN 'SLE' THEN 2 WHEN 'SOS' THEN 2 WHEN 'SRD' THEN 2 WHEN 'SSP' THEN 2 WHEN 'STN' THEN 2 WHEN 'SVC' THEN 2 WHEN 'SYP' THEN 2 WHEN 'SZL' THEN 2 WHEN 'THB' THEN 2 WHEN 'TJS' THEN 2 WHEN 'TMT' THEN 2 WHEN 'TND' THEN 3 WHEN 'TOP' THEN 2 WHEN 'TRY' THEN 2 WHEN 'TTD' THEN 2 WHEN 'TWD' THEN 2 WHEN 'TZS' THEN 2 WHEN 'UAH' THEN 2 WHEN 'UGX' THEN 0 WHEN 'USD' THEN 2 WHEN 'USN' THEN 2 WHEN 'UYI' THEN 0 WHEN 'UYU' THEN 2 WHEN 'UYW' THEN 4 WHEN 'UZS' THEN 2 WHEN 'VED' THEN 2 WHEN 'VES' THEN 2 WHEN 'VND' THEN 0 WHEN 'VUV' THEN 0 WHEN 'WST' THEN 2 WHEN 'XAD' THEN 2 WHEN 'XAF' THEN 0 WHEN 'XCD' THEN 2 WHEN 'XCG' THEN 2 WHEN 'XOF' THEN 0 WHEN 'XPF' THEN 0 WHEN 'YER' THEN 2 WHEN 'ZAR' THEN 2 WHEN 'ZMW' THEN 2 WHEN 'ZWG' THEN 2 ELSE -1 END)) NOT VALID;
            ALTER TABLE public.billium_execution_observation_emission ADD COLUMN created_xid xid8 NOT NULL DEFAULT pg_current_xact_id();
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
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
