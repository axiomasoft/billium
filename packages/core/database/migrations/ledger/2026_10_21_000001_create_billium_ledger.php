<?php

declare(strict_types=1);

use Billium\Ledger\Internal\Repositories\LedgerPrivileges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        app(LedgerPrivileges::class)->preflight();
        // D19/D22/D23/D60: authoritative dimensions are relational; numeric intermediates cannot overflow bigint.
        DB::unprepared(<<<'SQL'
            -- migrate:fresh drops tables but retains standalone PostgreSQL functions.
            CREATE OR REPLACE FUNCTION public.billium_ledger_json_safe(value jsonb) RETURNS boolean
            LANGUAGE sql IMMUTABLE STRICT SET search_path = pg_catalog, pg_temp AS $$
                SELECT jsonb_typeof(value) = 'object' AND octet_length(value::text) <= 65536
                   AND value::text !~* '"(password|secret|token|authorization|card_number|pan|cvv|cvc|api_key|access_token|refresh_token)"[[:space:]]*:'
            $$;
            REVOKE ALL ON FUNCTION public.billium_ledger_json_safe(jsonb) FROM PUBLIC;
            CREATE OR REPLACE FUNCTION public.billium_invoice_metadata_allowlist(value jsonb) RETURNS boolean
            LANGUAGE sql IMMUTABLE STRICT SET search_path = pg_catalog, pg_temp AS $$
                SELECT public.billium_ledger_json_safe(value)
                   AND NOT EXISTS (SELECT FROM jsonb_object_keys(CASE WHEN jsonb_typeof(value) = 'object' THEN value ELSE '{}'::jsonb END) AS k WHERE k NOT IN ('note', 'external_ref', 'labels'))
            $$;
            REVOKE ALL ON FUNCTION public.billium_invoice_metadata_allowlist(jsonb) FROM PUBLIC;

            CREATE TABLE public.billium_allocation_receipt (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
            seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
            environment text NOT NULL CHECK (environment IN ('test','live')),
            billing_account_ref text NOT NULL CHECK (billing_account_ref <> '' AND length(billing_account_ref) <= 200),
            currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
            receipt_ref text NOT NULL CHECK (receipt_ref <> ''),
            kind text NOT NULL CHECK (kind <> ''),
            source_ref text NULL,
            semantic_digest text NOT NULL CHECK (semantic_digest ~ '^sha256:[0-9a-f]{64}$'),
            body jsonb NOT NULL CHECK (public.billium_ledger_json_safe(body)),
            schema_version integer NOT NULL DEFAULT 1 CHECK (schema_version = 1),
            created_at timestamptz NOT NULL DEFAULT now(),
            UNIQUE (seller_ref,environment,receipt_ref),
            UNIQUE (installation_ref,seller_ref,environment,billing_account_ref,currency,receipt_ref),
            UNIQUE (seller_ref, environment, id),
            UNIQUE (installation_ref, seller_ref, environment, billing_account_ref, currency, id)
            );

            CREATE TABLE public.billium_invoice (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
            seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
            environment text NOT NULL CHECK (environment IN ('test','live')),
            billing_account_ref text NOT NULL CHECK (billing_account_ref <> '' AND length(billing_account_ref) <= 200),
            currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
            invoice_ref text NOT NULL CHECK (invoice_ref <> ''),
            customer_ref text NOT NULL CHECK (customer_ref <> ''),
            status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','issued','partially_paid','paid','uncollectible','void','discarded')),
            subtotal_minor bigint NOT NULL DEFAULT 0 CHECK (subtotal_minor >= 0),
            tax_minor bigint NOT NULL DEFAULT 0 CHECK (tax_minor >= 0),
            total_minor bigint NOT NULL DEFAULT 0 CHECK (total_minor >= 0 AND total_minor::numeric = subtotal_minor::numeric + tax_minor),
            allocated_minor bigint NOT NULL DEFAULT 0 CHECK (allocated_minor >= 0),
            credited_minor bigint NOT NULL DEFAULT 0 CHECK (credited_minor >= 0),
            open_minor bigint GENERATED ALWAYS AS ((total_minor::numeric-allocated_minor-credited_minor)::bigint) STORED,
            revision bigint NOT NULL DEFAULT 1 CHECK (revision >= 1),
            snapshot jsonb NOT NULL DEFAULT '{}' CHECK (public.billium_ledger_json_safe(snapshot)),
            metadata jsonb NOT NULL DEFAULT '{}' CHECK (public.billium_invoice_metadata_allowlist(metadata)),
            metadata_schema_version integer NOT NULL DEFAULT 1 CHECK (metadata_schema_version = 1),
            issued_at timestamptz NULL,
            legal_document_ref text NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            CHECK (allocated_minor::numeric + credited_minor <= total_minor),
            CHECK (status IN ('draft','discarded') OR issued_at IS NOT NULL),
            UNIQUE (seller_ref,environment,invoice_ref),
            UNIQUE (seller_ref, environment, id),
            UNIQUE (installation_ref, seller_ref, environment, billing_account_ref, currency, id)
            );

            CREATE TABLE public.billium_obligation (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
            seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
            environment text NOT NULL CHECK (environment IN ('test','live')),
            billing_account_ref text NOT NULL CHECK (billing_account_ref <> '' AND length(billing_account_ref) <= 200),
            currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
            obligation_ref text NOT NULL CHECK (obligation_ref <> ''),
            basis_ref text NOT NULL CHECK (basis_ref <> ''),
            customer_ref text NOT NULL CHECK (customer_ref <> ''),
            component_ref text NOT NULL CHECK (component_ref <> ''),
            amount_due_minor bigint NOT NULL CHECK (amount_due_minor >= 0),
            adjusted_minor bigint NOT NULL DEFAULT 0,
            allocated_minor bigint NOT NULL DEFAULT 0 CHECK (allocated_minor >= 0),
            credited_minor bigint NOT NULL DEFAULT 0 CHECK (credited_minor >= 0),
            open_minor bigint GENERATED ALWAYS AS ((amount_due_minor::numeric+adjusted_minor-allocated_minor-credited_minor)::bigint) STORED,
            terms_digest text NOT NULL CHECK (terms_digest ~ '^sha256:[0-9a-f]{64}$'),
            semantic_digest text NOT NULL CHECK (semantic_digest ~ '^sha256:[0-9a-f]{64}$'),
            price_version_ref text NULL,
            tax_snapshot_ref text NULL,
            currency_exponent_version text NOT NULL DEFAULT 'iso4217/2026-10',
            revision bigint NOT NULL DEFAULT 1 CHECK (revision >= 1),
            status text NOT NULL DEFAULT 'open' CHECK (status IN ('open','settled','cancelled')),
            billed_invoice_id bigint NULL,
            due_at timestamptz NULL,
            due_policy_version text NULL,
            source_policy_ref text NULL,
            source_policy_snapshot_digest text NULL,
            collection_eligible_at timestamptz NULL,
            receipt_ref text NOT NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            CHECK (amount_due_minor::numeric + adjusted_minor BETWEEN 0 AND 9223372036854775807),
            CHECK (allocated_minor::numeric + credited_minor <= amount_due_minor::numeric + adjusted_minor),
            CHECK ((due_at IS NULL AND collection_eligible_at IS NULL AND due_policy_version IS NULL AND source_policy_ref IS NULL AND source_policy_snapshot_digest IS NULL)
                OR (due_policy_version IS NOT NULL AND due_policy_version <> '' AND source_policy_ref IS NOT NULL AND source_policy_ref <> '' AND source_policy_snapshot_digest IS NOT NULL AND source_policy_snapshot_digest ~ '^sha256:[0-9a-f]{64}$')),
            UNIQUE (seller_ref,environment,obligation_ref),
            UNIQUE (seller_ref,environment,basis_ref),
            UNIQUE (seller_ref, environment, id),
            UNIQUE (installation_ref, seller_ref, environment, billing_account_ref, currency, id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,billed_invoice_id) REFERENCES public.billium_invoice(installation_ref,seller_ref,environment,billing_account_ref,currency,id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,receipt_ref) REFERENCES public.billium_allocation_receipt(installation_ref,seller_ref,environment,billing_account_ref,currency,receipt_ref)
            );
            CREATE INDEX billium_obligation_due_idx ON public.billium_obligation(seller_ref,environment,status,due_at,id) WHERE due_at IS NOT NULL;

            CREATE TABLE public.billium_adjustment (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
            seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
            environment text NOT NULL CHECK (environment IN ('test','live')),
            billing_account_ref text NOT NULL CHECK (billing_account_ref <> '' AND length(billing_account_ref) <= 200),
            currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
            adjustment_ref text NOT NULL CHECK (adjustment_ref <> ''),
            obligation_id bigint NOT NULL,
            kind text NOT NULL CHECK (kind IN ('increase','decrease')),
            amount_minor bigint NOT NULL CHECK (amount_minor > 0),
            cause_ref text NOT NULL CHECK (cause_ref <> ''),
            revision bigint NOT NULL CHECK (revision > 1),
            semantic_digest text NOT NULL CHECK (semantic_digest ~ '^sha256:[0-9a-f]{64}$'),
            receipt_ref text NOT NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            UNIQUE (seller_ref,environment,adjustment_ref),
            UNIQUE (seller_ref,environment,obligation_id,revision),
            UNIQUE (seller_ref, environment, id),
            UNIQUE (installation_ref, seller_ref, environment, billing_account_ref, currency, id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,obligation_id) REFERENCES public.billium_obligation(installation_ref,seller_ref,environment,billing_account_ref,currency,id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,receipt_ref) REFERENCES public.billium_allocation_receipt(installation_ref,seller_ref,environment,billing_account_ref,currency,receipt_ref)
            );

            CREATE TABLE public.billium_customer_credit (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
            seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
            environment text NOT NULL CHECK (environment IN ('test','live')),
            billing_account_ref text NOT NULL CHECK (billing_account_ref <> '' AND length(billing_account_ref) <= 200),
            currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
            credit_ref text NOT NULL CHECK (credit_ref <> ''),
            source_ref text NOT NULL CHECK (source_ref <> ''),
            amount_minor bigint NOT NULL CHECK (amount_minor > 0),
            remaining_minor bigint NOT NULL CHECK (remaining_minor BETWEEN 0 AND amount_minor),
            revision bigint NOT NULL DEFAULT 1 CHECK (revision >= 1),
            semantic_digest text NOT NULL CHECK (semantic_digest ~ '^sha256:[0-9a-f]{64}$'),
            receipt_ref text NOT NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            UNIQUE (seller_ref,environment,credit_ref),
            UNIQUE (seller_ref,environment,source_ref),
            UNIQUE (seller_ref, environment, id),
            UNIQUE (installation_ref, seller_ref, environment, billing_account_ref, currency, id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,receipt_ref) REFERENCES public.billium_allocation_receipt(installation_ref,seller_ref,environment,billing_account_ref,currency,receipt_ref)
            );

            CREATE TABLE public.billium_settlement_intent (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
            seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
            environment text NOT NULL CHECK (environment IN ('test','live')),
            billing_account_ref text NOT NULL CHECK (billing_account_ref <> '' AND length(billing_account_ref) <= 200),
            currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
            business_intent_ref text NOT NULL CHECK (business_intent_ref <> ''),
            policy_version text NOT NULL CHECK (policy_version = 'pro_rata_open/1'),
            semantic_digest text NOT NULL CHECK (semantic_digest ~ '^sha256:[0-9a-f]{64}$'),
            revision bigint NOT NULL DEFAULT 1 CHECK (revision >= 1),
            status text NOT NULL DEFAULT 'open' CHECK (status IN ('open','applied','closed_no_effect')),
            receipt_ref text NOT NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            UNIQUE (seller_ref,environment,business_intent_ref),
            UNIQUE (seller_ref, environment, id),
            UNIQUE (installation_ref, seller_ref, environment, billing_account_ref, currency, id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,receipt_ref) REFERENCES public.billium_allocation_receipt(installation_ref,seller_ref,environment,billing_account_ref,currency,receipt_ref)
            );

            CREATE TABLE public.billium_settlement_target (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
            seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
            environment text NOT NULL CHECK (environment IN ('test','live')),
            billing_account_ref text NOT NULL CHECK (billing_account_ref <> '' AND length(billing_account_ref) <= 200),
            currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
            settlement_intent_id bigint NOT NULL,
            obligation_id bigint NOT NULL,
            position integer NOT NULL CHECK (position >= 0),
            UNIQUE (seller_ref,environment,settlement_intent_id,position),
            UNIQUE (seller_ref,environment,settlement_intent_id,obligation_id),
            UNIQUE (seller_ref, environment, id),
            UNIQUE (installation_ref, seller_ref, environment, billing_account_ref, currency, id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,settlement_intent_id) REFERENCES public.billium_settlement_intent(installation_ref,seller_ref,environment,billing_account_ref,currency,id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,obligation_id) REFERENCES public.billium_obligation(installation_ref,seller_ref,environment,billing_account_ref,currency,id)
            );

            CREATE TABLE public.billium_settlement_intent_closure (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
            seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
            environment text NOT NULL CHECK (environment IN ('test','live')),
            billing_account_ref text NOT NULL CHECK (billing_account_ref <> '' AND length(billing_account_ref) <= 200),
            currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
            settlement_intent_id bigint NOT NULL,
            closure_ref text NOT NULL CHECK (closure_ref <> ''),
            expected_revision bigint NOT NULL CHECK (expected_revision >= 1),
            step_authorization_ref text NOT NULL CHECK (step_authorization_ref <> ''),
            proof_kind text NOT NULL CHECK (proof_kind IN ('not_admitted','not_dispatched')),
            proof_digest text NOT NULL CHECK (proof_digest ~ '^sha256:[0-9a-f]{64}$'),
            proof_evidence_ref text NOT NULL CHECK (proof_evidence_ref <> ''),
            cause text NOT NULL CHECK (cause <> ''),
            receipt_ref text NOT NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            UNIQUE (seller_ref,environment,closure_ref),
            UNIQUE (seller_ref,environment,receipt_ref),
            UNIQUE (seller_ref,environment,settlement_intent_id),
            UNIQUE (seller_ref, environment, id),
            UNIQUE (installation_ref, seller_ref, environment, billing_account_ref, currency, id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,settlement_intent_id) REFERENCES public.billium_settlement_intent(installation_ref,seller_ref,environment,billing_account_ref,currency,id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,receipt_ref) REFERENCES public.billium_allocation_receipt(installation_ref,seller_ref,environment,billing_account_ref,currency,receipt_ref)
            );

            CREATE TABLE public.billium_observed_payment (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
            seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
            environment text NOT NULL CHECK (environment IN ('test','live')),

            source_authority text NOT NULL CHECK (source_authority <> ''),
            source_lineage text NOT NULL CHECK (source_lineage <> ''),
            operation_ref text NOT NULL CHECK (operation_ref <> ''),
            kind text NOT NULL CHECK (kind IN ('charge','refund')),
            original_ref text NULL,
            revision bigint NOT NULL CHECK (revision >= 1),
            material_digest text NOT NULL CHECK (material_digest ~ '^sha256:[0-9a-f]{64}$'),
            state text NOT NULL CHECK (state IN ('pending','unknown','succeeded','failed','cancelled')),
            currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
            billing_account_ref text NULL CHECK (billing_account_ref IS NULL OR billing_account_ref <> ''),
            requested_minor bigint NOT NULL CHECK (requested_minor >= 0),
            authorized_minor bigint NOT NULL CHECK (authorized_minor >= 0),
            captured_minor bigint NOT NULL CHECK (captured_minor >= 0),
            refunded_minor bigint NOT NULL CHECK (refunded_minor >= 0),
            voided_minor bigint NOT NULL CHECK (voided_minor >= 0),
            provider_account_ref text NOT NULL,
            evidence_ref text NOT NULL,
            provider_metadata jsonb NOT NULL DEFAULT '{}' CHECK (public.billium_ledger_json_safe(provider_metadata)),
            metadata_schema_version integer NOT NULL DEFAULT 1 CHECK (metadata_schema_version = 1),
            received_at timestamptz NOT NULL DEFAULT now(),
            UNIQUE (seller_ref,environment,source_authority,source_lineage,operation_ref),
            UNIQUE (seller_ref,environment,id)
            );

            CREATE TABLE public.billium_observed_payment_revision (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
            seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
            environment text NOT NULL CHECK (environment IN ('test','live')),

            observed_payment_id bigint NOT NULL,
            revision bigint NOT NULL CHECK (revision >= 1),
            material_digest text NOT NULL CHECK (material_digest ~ '^sha256:[0-9a-f]{64}$'),
            state text NOT NULL CHECK (state IN ('pending','unknown','succeeded','failed','cancelled')),
            requested_minor bigint NOT NULL CHECK (requested_minor >= 0),
            authorized_minor bigint NOT NULL CHECK (authorized_minor >= 0),
            captured_minor bigint NOT NULL CHECK (captured_minor >= 0),
            refunded_minor bigint NOT NULL CHECK (refunded_minor >= 0),
            voided_minor bigint NOT NULL CHECK (voided_minor >= 0),
            currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
            evidence_ref text NOT NULL,
            payload jsonb NOT NULL DEFAULT '{}' CHECK (public.billium_ledger_json_safe(payload)),
            schema_version integer NOT NULL DEFAULT 1 CHECK (schema_version = 1),
            received_at timestamptz NOT NULL DEFAULT now(),
            UNIQUE (seller_ref,environment,observed_payment_id,revision),
            UNIQUE (seller_ref,environment,id),
            FOREIGN KEY (seller_ref,environment,observed_payment_id) REFERENCES public.billium_observed_payment(seller_ref,environment,id)
            );

            CREATE TABLE public.billium_payment (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
            seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
            environment text NOT NULL CHECK (environment IN ('test','live')),
            billing_account_ref text NOT NULL CHECK (billing_account_ref <> '' AND length(billing_account_ref) <= 200),
            currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
            payment_ref text NOT NULL CHECK (payment_ref <> ''),
            source_authority text NOT NULL CHECK (source_authority <> ''),
            source_lineage text NOT NULL CHECK (source_lineage <> ''),
            operation_ref text NOT NULL CHECK (operation_ref <> ''),
            kind text NOT NULL CHECK (kind IN ('charge','refund')),
            original_payment_id bigint NULL,
            settlement_intent_id bigint NULL,
            applied_revision bigint NOT NULL DEFAULT 0 CHECK (applied_revision >= 0),
            requested_minor bigint NOT NULL DEFAULT 0 CHECK (requested_minor >= 0),
            authorized_minor bigint NOT NULL DEFAULT 0 CHECK (authorized_minor >= 0),
            captured_minor bigint NOT NULL DEFAULT 0 CHECK (captured_minor >= 0),
            refunded_minor bigint NOT NULL DEFAULT 0 CHECK (refunded_minor BETWEEN 0 AND captured_minor),
            voided_minor bigint NOT NULL DEFAULT 0 CHECK (voided_minor >= 0),
            allocated_minor bigint NOT NULL DEFAULT 0 CHECK (allocated_minor >= 0),
            financial_hold boolean NOT NULL DEFAULT false,
            reallocation_required boolean NOT NULL DEFAULT false,
            projection_status text NOT NULL DEFAULT 'consistent' CHECK (projection_status IN ('consistent','held')),
            created_at timestamptz NOT NULL DEFAULT now(),
            CHECK (reallocation_required = (allocated_minor::numeric > captured_minor::numeric - refunded_minor)),
            CHECK (NOT financial_hold OR projection_status = 'held'),
            CHECK ((kind = 'charge' AND original_payment_id IS NULL) OR (kind = 'refund' AND original_payment_id IS NOT NULL)),
            UNIQUE (seller_ref,environment,payment_ref),
            UNIQUE (seller_ref,environment,source_authority,source_lineage,operation_ref),
            UNIQUE (seller_ref, environment, id),
            UNIQUE (installation_ref, seller_ref, environment, billing_account_ref, currency, id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,settlement_intent_id) REFERENCES public.billium_settlement_intent(installation_ref,seller_ref,environment,billing_account_ref,currency,id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,original_payment_id) REFERENCES public.billium_payment(installation_ref,seller_ref,environment,billing_account_ref,currency,id)
            );

            CREATE TABLE public.billium_payment_revision (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
            seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
            environment text NOT NULL CHECK (environment IN ('test','live')),
            billing_account_ref text NOT NULL CHECK (billing_account_ref <> '' AND length(billing_account_ref) <= 200),
            currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
            payment_id bigint NOT NULL,
            revision bigint NOT NULL CHECK (revision >= 1),
            material_digest text NOT NULL CHECK (material_digest ~ '^sha256:[0-9a-f]{64}$'),
            requested_minor bigint NOT NULL CHECK (requested_minor >= 0),
            authorized_minor bigint NOT NULL CHECK (authorized_minor >= 0),
            captured_minor bigint NOT NULL CHECK (captured_minor >= 0),
            refunded_minor bigint NOT NULL CHECK (refunded_minor BETWEEN 0 AND captured_minor),
            voided_minor bigint NOT NULL CHECK (voided_minor >= 0),
            receipt_ref text NOT NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            UNIQUE (seller_ref,environment,payment_id,revision),
            UNIQUE (seller_ref, environment, id),
            UNIQUE (installation_ref, seller_ref, environment, billing_account_ref, currency, id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,payment_id) REFERENCES public.billium_payment(installation_ref,seller_ref,environment,billing_account_ref,currency,id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,receipt_ref) REFERENCES public.billium_allocation_receipt(installation_ref,seller_ref,environment,billing_account_ref,currency,receipt_ref)
            );

            CREATE TABLE public.billium_allocation (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
            seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
            environment text NOT NULL CHECK (environment IN ('test','live')),
            billing_account_ref text NOT NULL CHECK (billing_account_ref <> '' AND length(billing_account_ref) <= 200),
            currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
            obligation_id bigint NOT NULL,
            payment_id bigint NULL,
            customer_credit_id bigint NULL,
            settlement_intent_id bigint NULL,
            amount_minor bigint NOT NULL CHECK (amount_minor <> 0 AND amount_minor > '-9223372036854775808'::bigint),
            reverses_id bigint NULL,
            cause text NOT NULL CHECK (cause <> ''),
            policy_version text NOT NULL,
            receipt_ref text NOT NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            CHECK (num_nonnulls(payment_id,customer_credit_id) = 1),
            CHECK ((reverses_id IS NULL AND amount_minor > 0) OR (reverses_id IS NOT NULL AND amount_minor < 0)),
            UNIQUE (seller_ref,environment,reverses_id),
            UNIQUE (seller_ref, environment, id),
            UNIQUE (installation_ref, seller_ref, environment, billing_account_ref, currency, id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,obligation_id) REFERENCES public.billium_obligation(installation_ref,seller_ref,environment,billing_account_ref,currency,id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,payment_id) REFERENCES public.billium_payment(installation_ref,seller_ref,environment,billing_account_ref,currency,id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,customer_credit_id) REFERENCES public.billium_customer_credit(installation_ref,seller_ref,environment,billing_account_ref,currency,id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,settlement_intent_id) REFERENCES public.billium_settlement_intent(installation_ref,seller_ref,environment,billing_account_ref,currency,id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,reverses_id) REFERENCES public.billium_allocation(installation_ref,seller_ref,environment,billing_account_ref,currency,id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,receipt_ref) REFERENCES public.billium_allocation_receipt(installation_ref,seller_ref,environment,billing_account_ref,currency,receipt_ref)
            );

            CREATE TABLE public.billium_invoice_line (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
            seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
            environment text NOT NULL CHECK (environment IN ('test','live')),
            billing_account_ref text NOT NULL CHECK (billing_account_ref <> '' AND length(billing_account_ref) <= 200),
            currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
            invoice_id bigint NOT NULL,
            obligation_id bigint NOT NULL,
            position integer NOT NULL CHECK (position >= 0),
            subtotal_minor bigint NOT NULL CHECK (subtotal_minor >= 0),
            tax_minor bigint NOT NULL CHECK (tax_minor >= 0),
            amount_minor bigint NOT NULL CHECK (amount_minor >= 0 AND amount_minor::numeric = subtotal_minor::numeric + tax_minor),
            snapshot jsonb NOT NULL DEFAULT '{}' CHECK (public.billium_ledger_json_safe(snapshot)),
            snapshot_schema_version integer NOT NULL DEFAULT 1 CHECK (snapshot_schema_version = 1),
            UNIQUE (seller_ref,environment,invoice_id,position),
            UNIQUE (seller_ref,environment,invoice_id,obligation_id),
            UNIQUE (seller_ref, environment, id),
            UNIQUE (installation_ref, seller_ref, environment, billing_account_ref, currency, id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,invoice_id) REFERENCES public.billium_invoice(installation_ref,seller_ref,environment,billing_account_ref,currency,id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,obligation_id) REFERENCES public.billium_obligation(installation_ref,seller_ref,environment,billing_account_ref,currency,id)
            );

            CREATE TABLE public.billium_credit_note (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
            seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
            environment text NOT NULL CHECK (environment IN ('test','live')),
            billing_account_ref text NOT NULL CHECK (billing_account_ref <> '' AND length(billing_account_ref) <= 200),
            currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
            credit_note_ref text NOT NULL CHECK (credit_note_ref <> ''),
            invoice_id bigint NULL,
            obligation_id bigint NOT NULL,
            amount_minor bigint NOT NULL CHECK (amount_minor > 0),
            reason text NOT NULL CHECK (reason <> ''),
            mode text NOT NULL CHECK (mode IN ('open_only','reverse_allocations')),
            legal_document_ref text NULL,
            receipt_ref text NOT NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            UNIQUE (seller_ref,environment,credit_note_ref),
            UNIQUE (seller_ref, environment, id),
            UNIQUE (installation_ref, seller_ref, environment, billing_account_ref, currency, id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,invoice_id) REFERENCES public.billium_invoice(installation_ref,seller_ref,environment,billing_account_ref,currency,id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,obligation_id) REFERENCES public.billium_obligation(installation_ref,seller_ref,environment,billing_account_ref,currency,id),
            FOREIGN KEY (installation_ref,seller_ref,environment,billing_account_ref,currency,receipt_ref) REFERENCES public.billium_allocation_receipt(installation_ref,seller_ref,environment,billing_account_ref,currency,receipt_ref)
            );

            CREATE TABLE public.billium_ledger_discrepancy (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
            seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
            environment text NOT NULL CHECK (environment IN ('test','live')),

            fingerprint text NOT NULL CHECK (fingerprint <> ''),
            comparison_basis_digest text NOT NULL CHECK (comparison_basis_digest ~ '^sha256:[0-9a-f]{64}$'),
            observed_payment_id bigint NOT NULL,
            observed_revision bigint NOT NULL,
            charge_revision bigint NULL,
            refund_revision bigint NULL,
            expected_minor bigint NOT NULL CHECK (expected_minor >= 0),
            actual_minor bigint NOT NULL CHECK (actual_minor >= 0),
            hold boolean NOT NULL DEFAULT true,
            reason text NOT NULL CHECK (reason <> ''),
            created_at timestamptz NOT NULL DEFAULT now(),
            UNIQUE (seller_ref,environment,fingerprint),
            UNIQUE (seller_ref,environment,id),
            FOREIGN KEY (seller_ref,environment,observed_payment_id,observed_revision) REFERENCES public.billium_observed_payment_revision(seller_ref,environment,observed_payment_id,revision)
            );

            CREATE TABLE public.billium_discrepancy_event (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
            seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
            environment text NOT NULL CHECK (environment IN ('test','live')),

            discrepancy_id bigint NOT NULL,
            event_ref text NOT NULL CHECK (event_ref <> ''),
            kind text NOT NULL CHECK (kind IN ('held','resolved')),
            evidence_ref text NOT NULL CHECK (evidence_ref <> ''),
            created_at timestamptz NOT NULL DEFAULT now(),
            UNIQUE (seller_ref,environment,event_ref),
            UNIQUE (seller_ref,environment,id),
            FOREIGN KEY (seller_ref,environment,discrepancy_id) REFERENCES public.billium_ledger_discrepancy(seller_ref,environment,id)
            );

            CREATE INDEX billium_invoice_open_idx ON public.billium_invoice(seller_ref,environment,status,open_minor,id) WHERE open_minor>0;
            CREATE INDEX billium_allocation_obligation_idx ON public.billium_allocation(seller_ref,environment,obligation_id,id);
            CREATE INDEX billium_allocation_payment_idx ON public.billium_allocation(seller_ref,environment,payment_id,id) WHERE payment_id IS NOT NULL;
            CREATE INDEX billium_allocation_credit_idx ON public.billium_allocation(seller_ref,environment,customer_credit_id,id) WHERE customer_credit_id IS NOT NULL;
            CREATE INDEX billium_payment_intent_idx ON public.billium_payment(seller_ref,environment,settlement_intent_id) WHERE settlement_intent_id IS NOT NULL;
            CREATE INDEX billium_credit_note_obligation_idx ON public.billium_credit_note(seller_ref,environment,obligation_id,id);


            CREATE OR REPLACE FUNCTION public.billium_observed_payment_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'billium: observed evidence is retained' USING ERRCODE='55000'; END IF;
                IF (NEW.id,NEW.installation_ref,NEW.seller_ref,NEW.environment,NEW.source_authority,NEW.source_lineage,NEW.operation_ref,NEW.kind,NEW.original_ref,NEW.currency,NEW.provider_account_ref)
                   IS DISTINCT FROM (OLD.id,OLD.installation_ref,OLD.seller_ref,OLD.environment,OLD.source_authority,OLD.source_lineage,OLD.operation_ref,OLD.kind,OLD.original_ref,OLD.currency,OLD.provider_account_ref)
                   OR (OLD.billing_account_ref IS NOT NULL AND NEW.billing_account_ref IS DISTINCT FROM OLD.billing_account_ref)
                THEN RAISE EXCEPTION 'billium: immutable observed source' USING ERRCODE='55000'; END IF;
                IF NEW.revision<=OLD.revision AND NOT (OLD.billing_account_ref IS NULL AND NEW.billing_account_ref IS NOT NULL
                    AND (to_jsonb(NEW)-'billing_account_ref') IS NOT DISTINCT FROM (to_jsonb(OLD)-'billing_account_ref'))
                THEN RAISE EXCEPTION 'billium: observed revision must advance' USING ERRCODE='55000'; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER billium_observed_payment_guard BEFORE UPDATE OR DELETE ON public.billium_observed_payment FOR EACH ROW EXECUTE FUNCTION public.billium_observed_payment_guard();
            REVOKE ALL ON FUNCTION public.billium_observed_payment_guard() FROM PUBLIC;

            CREATE OR REPLACE FUNCTION public.billium_obligation_terms_immutable() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'billium: immutable obligation' USING ERRCODE = '55000'; END IF;
                IF (NEW.id,NEW.installation_ref,NEW.seller_ref,NEW.environment,NEW.billing_account_ref,NEW.currency,NEW.obligation_ref,NEW.basis_ref,
                    NEW.customer_ref,NEW.component_ref,NEW.amount_due_minor,NEW.terms_digest,NEW.semantic_digest,NEW.price_version_ref,NEW.tax_snapshot_ref,NEW.currency_exponent_version,NEW.receipt_ref,NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.id,OLD.installation_ref,OLD.seller_ref,OLD.environment,OLD.billing_account_ref,OLD.currency,OLD.obligation_ref,OLD.basis_ref,
                    OLD.customer_ref,OLD.component_ref,OLD.amount_due_minor,OLD.terms_digest,OLD.semantic_digest,OLD.price_version_ref,OLD.tax_snapshot_ref,OLD.currency_exponent_version,OLD.receipt_ref,OLD.created_at)
                THEN RAISE EXCEPTION 'billium: immutable obligation terms (INV-12)' USING ERRCODE = '55000'; END IF;
                IF NEW.revision <> OLD.revision + 1 THEN RAISE EXCEPTION 'billium: obligation revision' USING ERRCODE = '55000'; END IF;
                IF NEW.adjusted_minor IS DISTINCT FROM OLD.adjusted_minor AND OLD.billed_invoice_id IS NOT NULL THEN
                    PERFORM 1 FROM public.billium_invoice WHERE id = OLD.billed_invoice_id AND seller_ref = OLD.seller_ref AND environment = OLD.environment AND status <> 'draft';
                    IF FOUND THEN RAISE EXCEPTION 'billium.invoice.frozen' USING ERRCODE = 'P0001'; END IF;
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER billium_obligation_terms_immutable BEFORE UPDATE OR DELETE ON public.billium_obligation FOR EACH ROW EXECUTE FUNCTION public.billium_obligation_terms_immutable();

            CREATE OR REPLACE FUNCTION public.billium_invoice_freeze() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'billium.invoice.frozen' USING ERRCODE = '55000'; END IF;
                IF OLD.status <> 'draft' AND
                   (NEW.id,NEW.installation_ref,NEW.seller_ref,NEW.environment,NEW.billing_account_ref,NEW.currency,NEW.customer_ref,NEW.invoice_ref,
                    NEW.subtotal_minor,NEW.tax_minor,NEW.total_minor,NEW.snapshot,NEW.issued_at,NEW.legal_document_ref,NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.id,OLD.installation_ref,OLD.seller_ref,OLD.environment,OLD.billing_account_ref,OLD.currency,OLD.customer_ref,OLD.invoice_ref,
                    OLD.subtotal_minor,OLD.tax_minor,OLD.total_minor,OLD.snapshot,OLD.issued_at,OLD.legal_document_ref,OLD.created_at)
                THEN RAISE EXCEPTION 'billium.invoice.frozen' USING ERRCODE = '55000'; END IF;
                IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                    (OLD.status = 'draft' AND NEW.status IN ('issued','discarded')) OR
                    (OLD.status = 'issued' AND NEW.status IN ('partially_paid','paid','uncollectible','void')) OR
                    (OLD.status = 'partially_paid' AND NEW.status IN ('issued','paid','uncollectible')) OR
                    (OLD.status = 'paid' AND NEW.status IN ('issued','partially_paid')) OR
                    (OLD.status = 'uncollectible' AND NEW.status IN ('partially_paid','paid'))
                ) THEN RAISE EXCEPTION 'billium.invoice.transition_invalid' USING ERRCODE = 'P0001'; END IF;
                IF NEW.status = 'void' AND NEW.allocated_minor <> 0 THEN RAISE EXCEPTION 'billium.invoice.transition_invalid' USING ERRCODE = 'P0001'; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER billium_invoice_freeze BEFORE UPDATE OR DELETE ON public.billium_invoice FOR EACH ROW EXECUTE FUNCTION public.billium_invoice_freeze();

            CREATE OR REPLACE FUNCTION public.billium_invoice_line_freeze() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            DECLARE v_invoice bigint; v_seller text; v_environment text;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    v_invoice := OLD.invoice_id; v_seller := OLD.seller_ref; v_environment := OLD.environment;
                    PERFORM 1 FROM public.billium_invoice WHERE id = v_invoice AND seller_ref = v_seller AND environment = v_environment AND status <> 'draft' FOR UPDATE;
                    IF FOUND THEN RAISE EXCEPTION 'billium.invoice.frozen' USING ERRCODE = '55000'; END IF;
                END IF;
                IF TG_OP <> 'DELETE' THEN
                    PERFORM 1 FROM public.billium_invoice WHERE id = NEW.invoice_id AND seller_ref = NEW.seller_ref AND environment = NEW.environment FOR UPDATE;
                    PERFORM 1 FROM public.billium_invoice WHERE id = NEW.invoice_id AND seller_ref = NEW.seller_ref AND environment = NEW.environment AND status <> 'draft';
                    IF FOUND THEN RAISE EXCEPTION 'billium.invoice.frozen' USING ERRCODE = '55000'; END IF;
                    RETURN NEW;
                END IF;
                RETURN OLD;
            END $$;
            CREATE TRIGGER billium_invoice_line_freeze BEFORE INSERT OR UPDATE OR DELETE ON public.billium_invoice_line FOR EACH ROW EXECUTE FUNCTION public.billium_invoice_line_freeze();

            CREATE OR REPLACE FUNCTION public.billium_settlement_intent_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'billium: immutable intent' USING ERRCODE = '55000'; END IF;
                IF (to_jsonb(NEW) - ARRAY['revision','status']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['revision','status'])
                   OR NEW.revision <> OLD.revision + 1 OR OLD.status = 'closed_no_effect'
                THEN RAISE EXCEPTION 'billium: immutable intent identity or invalid revision' USING ERRCODE = '55000'; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER billium_settlement_intent_guard BEFORE UPDATE OR DELETE ON public.billium_settlement_intent FOR EACH ROW EXECUTE FUNCTION public.billium_settlement_intent_guard();
            CREATE TRIGGER billium_adjustment_append_only BEFORE UPDATE OR DELETE ON public.billium_adjustment FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_allocation_append_only BEFORE UPDATE OR DELETE ON public.billium_allocation FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_allocation_receipt_append_only BEFORE UPDATE OR DELETE ON public.billium_allocation_receipt FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_settlement_target_append_only BEFORE UPDATE OR DELETE ON public.billium_settlement_target FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_settlement_intent_closure_append_only BEFORE UPDATE OR DELETE ON public.billium_settlement_intent_closure FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_payment_revision_append_only BEFORE UPDATE OR DELETE ON public.billium_payment_revision FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_observed_payment_revision_append_only BEFORE UPDATE OR DELETE ON public.billium_observed_payment_revision FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_credit_note_append_only BEFORE UPDATE OR DELETE ON public.billium_credit_note FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_ledger_discrepancy_append_only BEFORE UPDATE OR DELETE ON public.billium_ledger_discrepancy FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_discrepancy_event_append_only BEFORE UPDATE OR DELETE ON public.billium_discrepancy_event FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            REVOKE ALL ON FUNCTION public.billium_obligation_terms_immutable() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.billium_invoice_freeze() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.billium_invoice_line_freeze() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.billium_settlement_intent_guard() FROM PUBLIC;
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
