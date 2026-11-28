<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

/**
 * Collection authorization: authorizations, dispatch permits and no-effect records.
 */
return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_collection_authorization (
                authorization_ref text NOT NULL,
                installation_ref text NOT NULL,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test', 'live')),
                billing_account_ref text NOT NULL,
                obligation_ref text NOT NULL,
                intent_owner_namespace text NOT NULL,
                execution_profile text NOT NULL,
                routing_epoch bigint NOT NULL CHECK (routing_epoch >= 0),
                case_id bigint NOT NULL,
                recurring_owner_id bigint NULL REFERENCES public.billium_collection_recurring_owner(id),
                step_id bigint NOT NULL UNIQUE REFERENCES public.billium_collection_step(id),
                operation_ref text NOT NULL,
                business_intent_ref text NOT NULL,
                consent_ref text NOT NULL,
                consent_version bigint NOT NULL CHECK (consent_version > 0),
                consent_guard_id bigint NOT NULL REFERENCES public.billium_collection_consent_guard(id),
                consent_binding_id bigint NOT NULL REFERENCES public.billium_collection_consent_binding(id),
                cancellation_generation bigint NOT NULL CHECK (cancellation_generation >= 0),
                financial_epoch bigint NOT NULL CHECK (financial_epoch > 0),
                issuance_generation bigint NOT NULL CHECK (issuance_generation > 0),
                currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
                amount_minor bigint NOT NULL CHECK (amount_minor > 0),
                merchant_account_ref text NOT NULL,
                merchant_revision_ref text NOT NULL,
                policy_version text NOT NULL,
                basis_digest text NOT NULL CHECK (basis_digest ~ '^sha256:[0-9a-f]{64}$'),
                business_digest text NOT NULL CHECK (business_digest ~ '^sha256:[0-9a-f]{64}$'),
                issued_at timestamptz NOT NULL,
                PRIMARY KEY (seller_ref, environment, authorization_ref),
                UNIQUE (seller_ref, environment, operation_ref),
                UNIQUE (seller_ref, environment, business_intent_ref),
                FOREIGN KEY (case_id, seller_ref, environment) REFERENCES public.billium_collection_dunning_case(id, seller_ref, environment)
            );

            CREATE INDEX billium_collection_authorization_obligation_idx
                ON public.billium_collection_authorization(seller_ref, environment, obligation_ref);

            CREATE INDEX billium_collection_authorization_consent_idx
                ON public.billium_collection_authorization(seller_ref, environment, consent_ref);

            CREATE TRIGGER billium_collection_authorization_append_only
                BEFORE UPDATE OR DELETE ON public.billium_collection_authorization
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE TABLE public.billium_collection_dispatch_permit (
                step_id bigint PRIMARY KEY REFERENCES public.billium_collection_authorization(step_id),
                permit_ref text NOT NULL UNIQUE,
                attempt_ref text NOT NULL,
                lease_fence bigint NOT NULL,
                request_digest text NOT NULL CHECK (request_digest ~ '^sha256:[0-9a-f]{64}$'),
                committed_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE TRIGGER billium_collection_dispatch_permit_append_only
                BEFORE UPDATE OR DELETE ON public.billium_collection_dispatch_permit
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE TABLE public.billium_collection_no_effect (
                step_id bigint PRIMARY KEY REFERENCES public.billium_collection_authorization(step_id),
                closure_ref text NOT NULL UNIQUE,
                ledger_receipt_ref text NOT NULL,
                evidence_ref text NOT NULL,
                proof_kind text NOT NULL CHECK (proof_kind IN ('not_admitted', 'not_dispatched')),
                closed_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE TRIGGER billium_collection_no_effect_append_only
                BEFORE UPDATE OR DELETE ON public.billium_collection_no_effect
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            SQL);

        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_collection_authorization');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_collection_dispatch_permit');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_collection_no_effect');
    }

    public function down(): never
    {
        throw new LogicException('Billium migrations are forward-only.');
    }
};
