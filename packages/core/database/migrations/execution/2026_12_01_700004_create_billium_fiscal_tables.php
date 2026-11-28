<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

/**
 * Fiscal boundary: fiscal policies, intents, evidence and projections.
 */
return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.billium_fiscal_append_only() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                RAISE EXCEPTION 'billium: fiscal custody/history table % is append-only', TG_TABLE_NAME
                    USING ERRCODE = '55000';
            END $$;
            REVOKE ALL ON FUNCTION public.billium_fiscal_append_only() FROM PUBLIC;

            CREATE TABLE public.billium_fiscal_policy (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK (length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK (length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK (environment IN ('test', 'live')),
                execution_profile text NOT NULL CHECK (execution_profile IN ('standalone-billium', 'remote-billium', 'embedded-commerz')),
                operation_kind text NOT NULL CHECK (operation_kind IN ('charge', 'capture', 'refund', 'correction')),
                policy_version integer NOT NULL CHECK (policy_version >= 1),
                receipt_required boolean NOT NULL,
                fiscal_route text NOT NULL CHECK (fiscal_route IN ('psp_passthrough', 'fiscal_driver', 'projection_only', 'none')),
                driver_id text NULL CHECK (driver_id IS NULL OR length(driver_id) BETWEEN 1 AND 200),
                driver_contract_version text NULL CHECK (driver_contract_version IS NULL OR length(driver_contract_version) BETWEEN 1 AND 200),
                callback_trust_strategy text NULL CHECK (callback_trust_strategy IS NULL OR callback_trust_strategy IN ('hmac_signature', 'driver_verified', 'mutual_tls')),
                policy_digest text NOT NULL CHECK (policy_digest ~ '^sha256:[0-9a-f]{64}$'),
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(metadata) = 'object'),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (installation_ref, seller_ref, environment, execution_profile, operation_kind, policy_version),
                CHECK (
                    execution_profile NOT IN ('standalone-billium', 'remote-billium')
                    OR fiscal_route IN ('psp_passthrough', 'fiscal_driver', 'none')
                ),
                CHECK (
                    execution_profile <> 'embedded-commerz'
                    OR fiscal_route IN ('projection_only', 'none')
                ),
                CHECK (NOT receipt_required OR fiscal_route <> 'none'),
                CHECK (
                    fiscal_route NOT IN ('psp_passthrough', 'fiscal_driver')
                    OR (driver_id IS NOT NULL AND driver_contract_version IS NOT NULL)
                ),
                CHECK (
                    fiscal_route NOT IN ('projection_only', 'none')
                    OR (driver_id IS NULL AND driver_contract_version IS NULL)
                )
            );

            CREATE INDEX billium_fiscal_policy_latest_idx
                ON public.billium_fiscal_policy (installation_ref, seller_ref, environment, execution_profile, operation_kind, policy_version DESC, id DESC);

            CREATE TRIGGER billium_fiscal_policy_append_only
                BEFORE UPDATE OR DELETE ON public.billium_fiscal_policy
                FOR EACH ROW EXECUTE FUNCTION public.billium_fiscal_append_only();

            CREATE TABLE public.billium_fiscal_intent (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK (length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK (length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK (environment IN ('test', 'live')),
                intent_ref text NOT NULL CHECK (length(intent_ref) BETWEEN 1 AND 200),
                execution_profile text NOT NULL CHECK (execution_profile IN ('standalone-billium', 'remote-billium')),
                economic_effect_ref text NOT NULL CHECK (length(economic_effect_ref) BETWEEN 1 AND 200),
                root_operation_ref text NOT NULL CHECK (length(root_operation_ref) BETWEEN 1 AND 200),
                operation_ref text NOT NULL CHECK (length(operation_ref) BETWEEN 1 AND 200),
                receipt_kind text NOT NULL CHECK (receipt_kind IN ('sale', 'refund', 'correction_sale', 'correction_refund')),
                correction_revision integer NOT NULL DEFAULT 0 CHECK (correction_revision >= 0),
                parent_fiscal_intent_ref text NULL CHECK (parent_fiscal_intent_ref IS NULL OR length(parent_fiscal_intent_ref) BETWEEN 1 AND 200),
                external_id text NOT NULL CHECK (length(external_id) BETWEEN 1 AND 200),
                fiscal_route text NOT NULL CHECK (fiscal_route IN ('psp_passthrough', 'fiscal_driver')),
                driver_id text NOT NULL CHECK (length(driver_id) BETWEEN 1 AND 200),
                driver_contract_version text NOT NULL CHECK (length(driver_contract_version) BETWEEN 1 AND 200),
                callback_trust_strategy text NOT NULL DEFAULT 'driver_verified' CHECK (callback_trust_strategy IN ('hmac_signature', 'driver_verified', 'mutual_tls')),
                merchant_revision_ref text NOT NULL CHECK (length(merchant_revision_ref) BETWEEN 1 AND 200),
                merchant_account_ref text NOT NULL CHECK (length(merchant_account_ref) BETWEEN 1 AND 200),
                provider_evidence_ref text NOT NULL CHECK (length(provider_evidence_ref) BETWEEN 1 AND 200),
                policy_version integer NOT NULL CHECK (policy_version >= 1),
                policy_digest text NOT NULL CHECK (policy_digest ~ '^sha256:[0-9a-f]{64}$'),
                amount_minor bigint NOT NULL CHECK (amount_minor > 0),
                currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
                currency_exponent integer NOT NULL CHECK (currency_exponent BETWEEN 0 AND 4),
                exceptional boolean NOT NULL DEFAULT false,
                exposure_provenance jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(exposure_provenance) = 'object'),
                payload_snapshot jsonb NOT NULL CHECK (jsonb_typeof(payload_snapshot) = 'object'),
                payload_digest text NOT NULL CHECK (payload_digest ~ '^sha256:[0-9a-f]{64}$'),
                state text NOT NULL DEFAULT 'prepared' CHECK (state IN ('prepared', 'submitted', 'unknown', 'registered', 'rejected_terminal', 'intervention')),
                provider_receipt_id text NULL CHECK (provider_receipt_id IS NULL OR length(provider_receipt_id) BETWEEN 1 AND 200),
                fiscal_document_number text NULL CHECK (fiscal_document_number IS NULL OR length(fiscal_document_number) BETWEEN 1 AND 200),
                last_error_code text NULL CHECK (last_error_code IS NULL OR length(last_error_code) BETWEEN 1 AND 200),
                lookup_attempts integer NOT NULL DEFAULT 0 CHECK (lookup_attempts >= 0),
                next_lookup_at timestamptz NULL,
                lease_owner text NULL CHECK (lease_owner IS NULL OR length(lease_owner) BETWEEN 1 AND 200),
                lease_expires_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (installation_ref, seller_ref, environment, intent_ref),
                UNIQUE (installation_ref, seller_ref, environment, economic_effect_ref, receipt_kind, correction_revision),
                UNIQUE (installation_ref, seller_ref, environment, external_id),
                CHECK (
                    (correction_revision = 0 AND parent_fiscal_intent_ref IS NULL)
                    OR (correction_revision > 0 AND parent_fiscal_intent_ref IS NOT NULL)
                ),
                CHECK (NOT exceptional OR exposure_provenance <> '{}'::jsonb)
            );

            CREATE INDEX billium_fiscal_intent_due_lookup
                ON public.billium_fiscal_intent (seller_ref, state, next_lookup_at, id);

            CREATE INDEX billium_fiscal_intent_root_idx
                ON public.billium_fiscal_intent (installation_ref, seller_ref, environment, root_operation_ref, id);

            CREATE FUNCTION public.billium_fiscal_intent_guard() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium: fiscal intent cannot be deleted' USING ERRCODE = '55000';
                END IF;

                IF (
                    NEW.installation_ref,
                    NEW.seller_ref,
                    NEW.environment,
                    NEW.intent_ref,
                    NEW.execution_profile,
                    NEW.economic_effect_ref,
                    NEW.root_operation_ref,
                    NEW.operation_ref,
                    NEW.receipt_kind,
                    NEW.correction_revision,
                    NEW.parent_fiscal_intent_ref,
                    NEW.external_id,
                    NEW.fiscal_route,
                    NEW.driver_id,
                    NEW.driver_contract_version,
                    NEW.callback_trust_strategy,
                    NEW.merchant_revision_ref,
                    NEW.merchant_account_ref,
                    NEW.provider_evidence_ref,
                    NEW.policy_version,
                    NEW.policy_digest,
                    NEW.amount_minor,
                    NEW.currency,
                    NEW.currency_exponent,
                    NEW.exceptional,
                    NEW.exposure_provenance,
                    NEW.payload_snapshot,
                    NEW.payload_digest,
                    NEW.created_at
                ) IS DISTINCT FROM (
                    OLD.installation_ref,
                    OLD.seller_ref,
                    OLD.environment,
                    OLD.intent_ref,
                    OLD.execution_profile,
                    OLD.economic_effect_ref,
                    OLD.root_operation_ref,
                    OLD.operation_ref,
                    OLD.receipt_kind,
                    OLD.correction_revision,
                    OLD.parent_fiscal_intent_ref,
                    OLD.external_id,
                    OLD.fiscal_route,
                    OLD.driver_id,
                    OLD.driver_contract_version,
                    OLD.callback_trust_strategy,
                    OLD.merchant_revision_ref,
                    OLD.merchant_account_ref,
                    OLD.provider_evidence_ref,
                    OLD.policy_version,
                    OLD.policy_digest,
                    OLD.amount_minor,
                    OLD.currency,
                    OLD.currency_exponent,
                    OLD.exceptional,
                    OLD.exposure_provenance,
                    OLD.payload_snapshot,
                    OLD.payload_digest,
                    OLD.created_at
                ) THEN
                    RAISE EXCEPTION 'billium: fiscal intent identity, route and frozen snapshot are immutable' USING ERRCODE = '55000';
                END IF;

                IF OLD.provider_receipt_id IS NOT NULL AND NEW.provider_receipt_id IS DISTINCT FROM OLD.provider_receipt_id THEN
                    RAISE EXCEPTION 'billium: fiscal provider_receipt_id is immutable once assigned' USING ERRCODE = '55000';
                END IF;

                IF OLD.state IN ('registered', 'rejected_terminal') THEN
                    RAISE EXCEPTION 'billium: fiscal intent in terminal state % is immutable', OLD.state USING ERRCODE = '23514';
                END IF;

                IF NEW.lookup_attempts < OLD.lookup_attempts THEN
                    RAISE EXCEPTION 'billium: fiscal lookup_attempts must be monotonic' USING ERRCODE = '23514';
                END IF;

                IF NEW.state <> OLD.state THEN
                    IF OLD.state = 'prepared' AND NEW.state NOT IN ('submitted', 'unknown', 'registered', 'rejected_terminal', 'intervention') THEN
                        RAISE EXCEPTION 'billium: invalid fiscal transition % -> %', OLD.state, NEW.state USING ERRCODE = '23514';
                    ELSIF OLD.state = 'submitted' AND NEW.state NOT IN ('unknown', 'registered', 'rejected_terminal', 'intervention') THEN
                        RAISE EXCEPTION 'billium: invalid fiscal transition % -> %', OLD.state, NEW.state USING ERRCODE = '23514';
                    ELSIF OLD.state = 'unknown' AND NEW.state NOT IN ('registered', 'rejected_terminal', 'intervention') THEN
                        RAISE EXCEPTION 'billium: invalid fiscal transition % -> % (unknown requires lookup, never re-register)', OLD.state, NEW.state USING ERRCODE = '23514';
                    ELSIF OLD.state = 'intervention' AND NEW.state NOT IN ('registered', 'rejected_terminal') THEN
                        RAISE EXCEPTION 'billium: invalid fiscal transition % -> %', OLD.state, NEW.state USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_fiscal_intent_guard() FROM PUBLIC;

            CREATE TRIGGER billium_fiscal_intent_guard_trg
                BEFORE UPDATE OR DELETE ON public.billium_fiscal_intent
                FOR EACH ROW EXECUTE FUNCTION public.billium_fiscal_intent_guard();

            CREATE TABLE public.billium_fiscal_evidence (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK (length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK (length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK (environment IN ('test', 'live')),
                evidence_ref text NOT NULL CHECK (length(evidence_ref) BETWEEN 1 AND 200),
                fiscal_intent_ref text NOT NULL CHECK (length(fiscal_intent_ref) BETWEEN 1 AND 200),
                evidence_kind text NOT NULL CHECK (evidence_kind IN ('register_response', 'lookup_response', 'callback', 'transport_failure', 'callback_rejected')),
                driver_id text NOT NULL CHECK (length(driver_id) BETWEEN 1 AND 200),
                driver_contract_version text NOT NULL CHECK (length(driver_contract_version) BETWEEN 1 AND 200),
                merchant_revision_ref text NOT NULL CHECK (length(merchant_revision_ref) BETWEEN 1 AND 200),
                credential_version_ref text NULL CHECK (credential_version_ref IS NULL OR length(credential_version_ref) BETWEEN 1 AND 200),
                trust_verdict text NOT NULL CHECK (trust_verdict IN ('verified', 'rejected', 'not_applicable')),
                external_event_id text NULL CHECK (external_event_id IS NULL OR length(external_event_id) BETWEEN 1 AND 200),
                outcome_state text NOT NULL CHECK (outcome_state IN ('submitted', 'unknown', 'registered', 'rejected_terminal', 'intervention', 'duplicate_ignored', 'untrusted_rejected')),
                payload_digest text NOT NULL CHECK (payload_digest ~ '^sha256:[0-9a-f]{64}$'),
                sanitized_payload jsonb NOT NULL CHECK (jsonb_typeof(sanitized_payload) = 'object'),
                recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (installation_ref, seller_ref, environment, evidence_ref),
                FOREIGN KEY (installation_ref, seller_ref, environment, fiscal_intent_ref)
                    REFERENCES public.billium_fiscal_intent (installation_ref, seller_ref, environment, intent_ref)
            );

            CREATE INDEX billium_fiscal_evidence_intent_idx
                ON public.billium_fiscal_evidence (installation_ref, seller_ref, environment, fiscal_intent_ref, id);

            CREATE TRIGGER billium_fiscal_evidence_append_only
                BEFORE UPDATE OR DELETE ON public.billium_fiscal_evidence
                FOR EACH ROW EXECUTE FUNCTION public.billium_fiscal_append_only();

            CREATE TABLE public.billium_fiscal_projection (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK (length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK (length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK (environment IN ('test', 'live')),
                projection_ref text NOT NULL CHECK (length(projection_ref) BETWEEN 1 AND 200),
                execution_profile text NOT NULL CHECK (execution_profile = 'embedded-commerz'),
                source_authority text NOT NULL CHECK (length(source_authority) BETWEEN 1 AND 200),
                economic_effect_ref text NOT NULL CHECK (length(economic_effect_ref) BETWEEN 1 AND 200),
                root_operation_ref text NOT NULL CHECK (length(root_operation_ref) BETWEEN 1 AND 200),
                operation_ref text NOT NULL CHECK (length(operation_ref) BETWEEN 1 AND 200),
                receipt_kind text NOT NULL CHECK (receipt_kind IN ('sale', 'refund', 'correction_sale', 'correction_refund')),
                correction_revision integer NOT NULL DEFAULT 0 CHECK (correction_revision >= 0),
                external_fiscal_ref text NULL CHECK (external_fiscal_ref IS NULL OR length(external_fiscal_ref) BETWEEN 1 AND 200),
                fiscal_status text NOT NULL CHECK (fiscal_status IN ('pending', 'registered', 'rejected_terminal', 'unknown')),
                amount_minor bigint NOT NULL CHECK (amount_minor > 0),
                currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
                currency_exponent integer NOT NULL CHECK (currency_exponent BETWEEN 0 AND 4),
                exceptional boolean NOT NULL DEFAULT false,
                exposure_provenance jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(exposure_provenance) = 'object'),
                source_payload jsonb NOT NULL CHECK (jsonb_typeof(source_payload) = 'object'),
                source_digest text NOT NULL CHECK (source_digest ~ '^sha256:[0-9a-f]{64}$'),
                recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (installation_ref, seller_ref, environment, projection_ref),
                UNIQUE (installation_ref, seller_ref, environment, economic_effect_ref, receipt_kind, correction_revision, fiscal_status),
                CHECK (NOT exceptional OR exposure_provenance <> '{}'::jsonb)
            );

            CREATE INDEX billium_fiscal_projection_root_idx
                ON public.billium_fiscal_projection (installation_ref, seller_ref, environment, root_operation_ref, id);

            CREATE TRIGGER billium_fiscal_projection_append_only
                BEFORE UPDATE OR DELETE ON public.billium_fiscal_projection
                FOR EACH ROW EXECUTE FUNCTION public.billium_fiscal_append_only();
            SQL);

        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_fiscal_policy');
        RuntimeGrants::grant('SELECT, INSERT, UPDATE', 'public.billium_fiscal_intent');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_fiscal_evidence');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_fiscal_projection');
    }

    public function down(): never
    {
        throw new LogicException('Billium migrations are forward-only.');
    }
};
