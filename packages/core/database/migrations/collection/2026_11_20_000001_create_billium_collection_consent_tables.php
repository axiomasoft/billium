<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_collection_consent_binding (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                billing_account_ref text NOT NULL,
                customer_ref text NOT NULL,
                consent_ref text NOT NULL,
                consent_version integer NOT NULL CHECK (consent_version >= 1),
                payment_method_ref text NOT NULL,
                token_locator text NOT NULL,
                merchant_account_ref text NOT NULL,
                merchant_config_revision text NOT NULL,
                provider_id text NOT NULL,
                driver_id text NOT NULL,
                driver_contract_version text NOT NULL,
                capability_snapshot_ref text NOT NULL,
                capability_digest text NOT NULL,
                allowed_purposes jsonb NOT NULL CHECK (jsonb_typeof(allowed_purposes) = 'array' AND jsonb_array_length(allowed_purposes) > 0),
                allowed_currencies jsonb NOT NULL CHECK (jsonb_typeof(allowed_currencies) = 'array' AND jsonb_array_length(allowed_currencies) > 0),
                per_charge_cap_minor bigint NULL CHECK (per_charge_cap_minor IS NULL OR per_charge_cap_minor > 0),
                per_period_cap_minor bigint NULL CHECK (per_period_cap_minor IS NULL OR per_period_cap_minor > 0),
                cap_currency text NULL CHECK (cap_currency IS NULL OR cap_currency ~ '^[A-Z]{3}$'),
                captured_at timestamptz NOT NULL,
                valid_from timestamptz NOT NULL,
                valid_until timestamptz NULL,
                source_evidence_ref text NOT NULL,
                source_evidence_digest text NOT NULL,
                proof_digest text NOT NULL,
                supersedes_binding_id bigint NULL REFERENCES public.billium_collection_consent_binding(id),
                created_at timestamptz NOT NULL,
                UNIQUE (seller_ref, environment, consent_ref, consent_version),
                CHECK (valid_until IS NULL OR valid_until > valid_from),
                CHECK ((per_charge_cap_minor IS NULL AND per_period_cap_minor IS NULL) OR cap_currency IS NOT NULL),
                CHECK (per_charge_cap_minor IS NULL OR per_period_cap_minor IS NULL OR per_charge_cap_minor <= per_period_cap_minor),
                CHECK (payment_method_ref !~ '[0-9]{13,19}' AND payment_method_ref !~ '(^|[^0-9A-Za-z])([0-9][ -]?){13,19}($|[^0-9A-Za-z])'),
                CHECK (token_locator !~ '[0-9]{13,19}' AND token_locator !~ '(^|[^0-9A-Za-z])([0-9][ -]?){13,19}($|[^0-9A-Za-z])')
            );

            CREATE INDEX billium_collection_consent_binding_account_idx
                ON public.billium_collection_consent_binding (seller_ref, environment, billing_account_ref, consent_ref, consent_version DESC);

            CREATE INDEX billium_collection_consent_binding_merchant_idx
                ON public.billium_collection_consent_binding (seller_ref, environment, merchant_account_ref, merchant_config_revision);

            CREATE TABLE public.billium_collection_consent_guard (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                billing_account_ref text NOT NULL,
                consent_ref text NOT NULL,
                active_binding_id bigint NOT NULL REFERENCES public.billium_collection_consent_binding(id),
                active_consent_version integer NOT NULL CHECK (active_consent_version >= 1),
                guard_revision bigint NOT NULL CHECK (guard_revision >= 1),
                source_revision bigint NOT NULL CHECK (source_revision >= 0),
                cancellation_generation bigint NOT NULL CHECK (cancellation_generation >= 0),
                status text NOT NULL CHECK (status IN ('active','revoked','cancelled')),
                revoked_at timestamptz NULL,
                revocation_receipt_ref text NULL,
                revocation_reason text NULL,
                effective_at timestamptz NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                UNIQUE (seller_ref, environment, consent_ref),
                CHECK (
                    status = 'active'
                    OR (
                        revoked_at IS NOT NULL
                        AND revocation_receipt_ref IS NOT NULL
                        AND effective_at IS NOT NULL
                    )
                ),
                CHECK (
                    status <> 'active'
                    OR (
                        revoked_at IS NULL
                        AND revocation_receipt_ref IS NULL
                        AND effective_at IS NULL
                    )
                )
            );

            CREATE INDEX billium_collection_consent_guard_account_idx
                ON public.billium_collection_consent_guard (seller_ref, environment, billing_account_ref, status);

            CREATE TABLE public.billium_collection_guard_tombstone (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                consent_guard_id bigint NOT NULL REFERENCES public.billium_collection_consent_guard(id),
                consent_ref text NOT NULL,
                consent_version integer NOT NULL CHECK (consent_version >= 1),
                tombstone_ref text NOT NULL,
                tombstone_kind text NOT NULL CHECK (tombstone_kind IN ('consent_revoked','host_cancelled','authorization_tombstone')),
                target_authorization_ref text NULL,
                target_business_intent_ref text NULL,
                guard_revision bigint NOT NULL CHECK (guard_revision >= 1),
                source_revision bigint NOT NULL CHECK (source_revision >= 0),
                cancellation_generation bigint NOT NULL CHECK (cancellation_generation >= 0),
                reason text NOT NULL,
                evidence_digest text NOT NULL,
                effective_at timestamptz NOT NULL,
                recorded_at timestamptz NOT NULL,
                UNIQUE (seller_ref, environment, tombstone_ref),
                UNIQUE (seller_ref, environment, consent_guard_id, guard_revision)
            );

            CREATE INDEX billium_collection_guard_tombstone_auth_idx
                ON public.billium_collection_guard_tombstone (seller_ref, environment, target_authorization_ref)
                WHERE target_authorization_ref IS NOT NULL;

            CREATE INDEX billium_collection_guard_tombstone_intent_idx
                ON public.billium_collection_guard_tombstone (seller_ref, environment, target_business_intent_ref)
                WHERE target_business_intent_ref IS NOT NULL;

            CREATE TABLE public.billium_collection_customer_action (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                action_ref text NOT NULL,
                billing_account_ref text NOT NULL,
                consent_binding_id bigint NOT NULL REFERENCES public.billium_collection_consent_binding(id),
                consent_ref text NOT NULL,
                consent_version integer NOT NULL CHECK (consent_version >= 1),
                operation_ref text NULL,
                business_payment_intent_ref text NOT NULL,
                action_type text NOT NULL CHECK (action_type IN ('three_ds_challenge','mandate_confirmation','redirect_authentication','sca_challenge')),
                status text NOT NULL CHECK (status IN ('requires_action','expired','superseded')),
                challenge_ref text NOT NULL,
                action_payload jsonb NOT NULL CHECK (jsonb_typeof(action_payload) = 'object'),
                expires_at timestamptz NOT NULL,
                receipt_digest text NOT NULL,
                recorded_at timestamptz NOT NULL,
                UNIQUE (seller_ref, environment, action_ref),
                UNIQUE (seller_ref, environment, business_payment_intent_ref, challenge_ref)
            );

            CREATE TABLE public.billium_collection_refund_route (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                operation_ref text NOT NULL,
                billing_account_ref text NOT NULL,
                original_binding_id bigint NOT NULL REFERENCES public.billium_collection_consent_binding(id),
                original_consent_ref text NOT NULL,
                original_consent_version integer NOT NULL CHECK (original_consent_version >= 1),
                original_merchant_account_ref text NOT NULL,
                original_merchant_config_revision text NOT NULL,
                original_provider_id text NOT NULL,
                original_driver_id text NOT NULL,
                original_driver_contract_version text NOT NULL,
                original_payment_method_ref text NOT NULL,
                route_digest text NOT NULL,
                recorded_at timestamptz NOT NULL,
                UNIQUE (seller_ref, environment, operation_ref)
            );

            CREATE TRIGGER billium_collection_consent_binding_immutable
                BEFORE UPDATE OR DELETE ON public.billium_collection_consent_binding
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE TRIGGER billium_collection_guard_tombstone_immutable
                BEFORE UPDATE OR DELETE ON public.billium_collection_guard_tombstone
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE TRIGGER billium_collection_refund_route_immutable
                BEFORE UPDATE OR DELETE ON public.billium_collection_refund_route
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE OR REPLACE FUNCTION public.billium_collection_consent_guard_check()
            RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium_collection_consent_guard cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF (NEW.seller_ref, NEW.environment, NEW.billing_account_ref, NEW.consent_ref)
                   IS DISTINCT FROM
                   (OLD.seller_ref, OLD.environment, OLD.billing_account_ref, OLD.consent_ref) THEN
                    RAISE EXCEPTION 'billium_collection_consent_guard identity is immutable' USING ERRCODE = '23514';
                END IF;
                IF NEW.guard_revision <> OLD.guard_revision + 1 THEN
                    RAISE EXCEPTION 'billium_collection_consent_guard revision must advance monotonically by 1' USING ERRCODE = '23514';
                END IF;
                IF NEW.source_revision < OLD.source_revision OR NEW.cancellation_generation < OLD.cancellation_generation THEN
                    RAISE EXCEPTION 'billium_collection_consent_guard source_revision and cancellation_generation cannot regress' USING ERRCODE = '23514';
                END IF;
                IF NEW.active_consent_version < OLD.active_consent_version THEN
                    RAISE EXCEPTION 'billium_collection_consent_guard active_consent_version cannot regress' USING ERRCODE = '23514';
                END IF;
                IF OLD.status IN ('revoked', 'cancelled') AND NEW.status = 'active' THEN
                    RAISE EXCEPTION 'billium_collection_consent_guard cannot revive terminal % state', OLD.status USING ERRCODE = '23514';
                END IF;
                IF OLD.revoked_at IS NOT NULL AND (NEW.revoked_at IS NULL OR NEW.revocation_receipt_ref IS NULL OR NEW.effective_at IS NULL) THEN
                    RAISE EXCEPTION 'billium_collection_consent_guard revocation provenance cannot be cleared' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;

            REVOKE ALL ON FUNCTION public.billium_collection_consent_guard_check() FROM PUBLIC;

            CREATE TRIGGER billium_collection_consent_guard_trg
                BEFORE UPDATE OR DELETE ON public.billium_collection_consent_guard
                FOR EACH ROW EXECUTE FUNCTION public.billium_collection_consent_guard_check();

            CREATE OR REPLACE FUNCTION public.billium_collection_customer_action_guard()
            RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium_collection_customer_action cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF (
                    NEW.seller_ref, NEW.environment, NEW.action_ref, NEW.billing_account_ref,
                    NEW.consent_binding_id, NEW.consent_ref, NEW.consent_version, NEW.business_payment_intent_ref,
                    NEW.action_type, NEW.challenge_ref, NEW.action_payload, NEW.expires_at,
                    NEW.receipt_digest, NEW.recorded_at
                ) IS DISTINCT FROM (
                    OLD.seller_ref, OLD.environment, OLD.action_ref, OLD.billing_account_ref,
                    OLD.consent_binding_id, OLD.consent_ref, OLD.consent_version, OLD.business_payment_intent_ref,
                    OLD.action_type, OLD.challenge_ref, OLD.action_payload, OLD.expires_at,
                    OLD.receipt_digest, OLD.recorded_at
                ) THEN
                    RAISE EXCEPTION 'billium_collection_customer_action receipt fields are immutable' USING ERRCODE = '23514';
                END IF;
                IF OLD.status IN ('expired', 'superseded') AND NEW.status = 'requires_action' THEN
                    RAISE EXCEPTION 'billium_collection_customer_action cannot revive terminal % state', OLD.status USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;

            REVOKE ALL ON FUNCTION public.billium_collection_customer_action_guard() FROM PUBLIC;

            CREATE TRIGGER billium_collection_customer_action_trg
                BEFORE UPDATE OR DELETE ON public.billium_collection_customer_action
                FOR EACH ROW EXECUTE FUNCTION public.billium_collection_customer_action_guard();
            SQL);

        $runtime = config('billium.connection') ?? config('database.default');
        $username = DB::connection($runtime)->getConfig('username');
        if (! is_string($username) || $username === '') {
            throw new LogicException('Collection runtime role is required');
        }
        $role = '"'.str_replace('"', '""', $username).'"';
        foreach (['collection_consent_binding', 'collection_guard_tombstone', 'collection_refund_route'] as $table) {
            DB::unprepared('REVOKE ALL ON TABLE public.billium_'.$table.' FROM PUBLIC, '.$role);
            DB::unprepared('GRANT SELECT, INSERT ON TABLE public.billium_'.$table.' TO '.$role);
        }
        foreach (['collection_consent_guard', 'collection_customer_action'] as $table) {
            DB::unprepared('REVOKE ALL ON TABLE public.billium_'.$table.' FROM PUBLIC, '.$role);
            DB::unprepared('GRANT SELECT, INSERT, UPDATE ON TABLE public.billium_'.$table.' TO '.$role);
        }
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
