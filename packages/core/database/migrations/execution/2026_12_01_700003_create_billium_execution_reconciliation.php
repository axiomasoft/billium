<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

/**
 * Execution reconciliation: bindings, pages, inventory items, reviews and original replays and imports.
 */
return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_execution_recon_binding (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                run_id bigint NOT NULL UNIQUE REFERENCES public.billium_execution_reconciliation_run(id),
                owner_namespace text NOT NULL CHECK (length(owner_namespace) BETWEEN 1 AND 200),
                source_authority text NOT NULL CHECK (source_authority IN ('remote-billium','standalone-billium')),
                revision_ref text NOT NULL CHECK (length(revision_ref) BETWEEN 1 AND 200),
                credential_version_ref text NOT NULL CHECK (length(credential_version_ref) BETWEEN 1 AND 200),
                provider_id text NOT NULL CHECK (length(provider_id) BETWEEN 1 AND 200),
                currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
                through_at timestamptz NOT NULL,
                workflow text NOT NULL CHECK (workflow IN ('inventory','targeted')),
                attempt_ref text CHECK (length(attempt_ref) BETWEEN 1 AND 200),
                CHECK ((workflow='targeted') = (attempt_ref IS NOT NULL))
            );

            CREATE INDEX billium_execution_recon_binding_history ON public.billium_execution_recon_binding
                (owner_namespace,source_authority,revision_ref,credential_version_ref,currency,workflow,through_at);

            CREATE TRIGGER billium_execution_recon_binding_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_recon_binding FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();

            CREATE TABLE public.billium_execution_recon_page (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                run_id bigint NOT NULL REFERENCES public.billium_execution_recon_binding(run_id),
                page_no integer NOT NULL CHECK (page_no>0),
                cursor_in text CHECK (length(cursor_in) BETWEEN 1 AND 200),
                cursor_out text CHECK (length(cursor_out) BETWEEN 1 AND 200),
                report_ref text NOT NULL CHECK (length(report_ref) BETWEEN 1 AND 200),
                credential_version_ref text NOT NULL CHECK (length(credential_version_ref) BETWEEN 1 AND 200),
                source_digest text NOT NULL CHECK (source_digest ~ '^sha256:[0-9a-f]{64}$'),
                items_digest text NOT NULL CHECK (items_digest ~ '^sha256:[0-9a-f]{64}$'),
                complete_through timestamptz,
                item_count integer NOT NULL CHECK (item_count BETWEEN 0 AND 100),
                CHECK (cursor_out IS NULL OR (cursor_out IS DISTINCT FROM cursor_in AND complete_through IS NULL)),
                UNIQUE(run_id,page_no), UNIQUE NULLS NOT DISTINCT(run_id,cursor_in)
            );

            CREATE TRIGGER billium_execution_recon_page_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_recon_page FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();

            CREATE TABLE public.billium_execution_recon_item (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                run_id bigint NOT NULL,
                page_no integer NOT NULL,
                item_no integer NOT NULL CHECK (item_no BETWEEN 0 AND 99),
                fact_ref text NOT NULL CHECK (length(fact_ref) BETWEEN 1 AND 200),
                provider_revision text CHECK (length(provider_revision) BETWEEN 1 AND 200),
                evidence_ref text NOT NULL CHECK (length(evidence_ref) BETWEEN 1 AND 200),
                work_ref text CHECK (length(work_ref) BETWEEN 1 AND 200),
                kind text NOT NULL CHECK (kind IN ('charge','authorize','capture','void_authorization','refund','dispute','chargeback','fee','settlement','external_manual','balance_comparison')),
                root_ref text CHECK (length(root_ref) BETWEEN 1 AND 200),
                operation_ref text CHECK (length(operation_ref) BETWEEN 1 AND 200),
                source_digest text NOT NULL CHECK (length(source_digest) BETWEEN 1 AND 200),
                comparison_basis jsonb NOT NULL CHECK (jsonb_typeof(comparison_basis)='object' AND comparison_basis ? 'status'),
                observed jsonb NOT NULL CHECK (jsonb_typeof(observed)='object' AND observed<>'{}'::jsonb),
                disposition text NOT NULL CHECK (length(disposition) BETWEEN 1 AND 200),
                review_ref text CHECK (length(review_ref) BETWEEN 1 AND 200),
                UNIQUE(run_id,page_no,item_no),
                FOREIGN KEY (run_id,page_no) REFERENCES public.billium_execution_recon_page(run_id,page_no) DEFERRABLE INITIALLY DEFERRED,
                CONSTRAINT ex_recon_item_basis_status
                    CHECK ((jsonb_typeof(comparison_basis->'status')='string' AND length(comparison_basis->>'status') BETWEEN 1 AND 200) IS TRUE)
            );

            CREATE TRIGGER billium_execution_recon_item_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_recon_item FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();

            -- Manual repair (late refund adjudication, linked correction approval) finds the inventory item of a review
            -- inside its financial transaction. Items are retained from every reconciliation run and only discrepancies
            -- carry a review, so a partial index stays small and the lookup does not scan all retained items.
            CREATE INDEX billium_execution_recon_item_review_idx
                ON public.billium_execution_recon_item (review_ref)
                WHERE review_ref IS NOT NULL;

            CREATE TABLE public.billium_execution_recon_review (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK (length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK (length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK (length(environment) BETWEEN 1 AND 200),
                owner_namespace text NOT NULL CHECK (length(owner_namespace) BETWEEN 1 AND 200),
                source_authority text NOT NULL CHECK (source_authority IN ('remote-billium','standalone-billium')),
                merchant_account_ref text NOT NULL CHECK (length(merchant_account_ref) BETWEEN 1 AND 200),
                revision_ref text NOT NULL CHECK (length(revision_ref) BETWEEN 1 AND 200),
                currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
                root_ref text CHECK (length(root_ref) BETWEEN 1 AND 200),
                case_ref text NOT NULL CHECK (length(case_ref) BETWEEN 1 AND 200),
                event_ref text NOT NULL UNIQUE CHECK (length(event_ref) BETWEEN 1 AND 200),
                status text NOT NULL CHECK(status IN ('open','resolved')),
                code text NOT NULL CHECK(code IN ('missing_local_effect','material_conflict','balance_mismatch','dispute','chargeback','fee','settlement','external_manual','manual_required')),
                evidence_ref text NOT NULL CHECK (length(evidence_ref) BETWEEN 1 AND 200),
                source_digest text NOT NULL CHECK (length(source_digest) BETWEEN 1 AND 200),
                expected jsonb NOT NULL CHECK (jsonb_typeof(expected)='object' AND jsonb_typeof(expected->'status')='string' AND expected ? 'status'),
                observed jsonb NOT NULL CHECK (jsonb_typeof(observed)='object' AND (observed ? 'factRef' OR observed ? 'lookupState')),
                basis_digest text NOT NULL CHECK (basis_digest ~ '^sha256:[0-9a-f]{64}$'),
                provenance text NOT NULL CHECK (length(provenance) BETWEEN 1 AND 200),
                receipt_ref text CHECK (length(receipt_ref) BETWEEN 1 AND 200),
                severity text NOT NULL CHECK (severity IN ('review','high')),
                owner_ref text NOT NULL CHECK (length(owner_ref) BETWEEN 1 AND 200),
                created_at timestamptz NOT NULL,
                next_review_at timestamptz,
                CHECK(status<>'resolved' OR (receipt_ref IS NOT NULL AND next_review_at IS NULL)),
                CONSTRAINT ex_recon_expected_status
                    CHECK ((jsonb_typeof(expected->'status')='string' AND length(expected->>'status') BETWEEN 1 AND 200) IS TRUE),
                CONSTRAINT ex_recon_observed_identity
                    CHECK (((jsonb_typeof(observed->'factRef')='string' AND length(observed->>'factRef') BETWEEN 1 AND 200)
                    OR (jsonb_typeof(observed->'lookupState')='string' AND length(observed->>'lookupState') BETWEEN 1 AND 200)) IS TRUE)
            );

            CREATE INDEX billium_execution_recon_review_history ON public.billium_execution_recon_review (installation_ref,seller_ref,environment,owner_namespace,source_authority,case_ref,id DESC);

            CREATE INDEX billium_execution_recon_review_open ON public.billium_execution_recon_review (installation_ref,seller_ref,environment,owner_namespace,source_authority,status,created_at,id);

            CREATE INDEX billium_execution_recon_review_due ON public.billium_execution_recon_review (installation_ref,seller_ref,environment,owner_namespace,source_authority,status,next_review_at,id);

            CREATE TRIGGER billium_execution_recon_review_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_recon_review FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();

            CREATE TABLE public.billium_execution_original_replay (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK (length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK (length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK (length(environment) BETWEEN 1 AND 200),
                intent_owner_namespace text NOT NULL CHECK (length(intent_owner_namespace) BETWEEN 1 AND 200),
                approval_ref text NOT NULL CHECK (length(approval_ref) BETWEEN 1 AND 200),
                attempt_ref text NOT NULL CHECK (length(attempt_ref) BETWEEN 1 AND 200),
                operation_ref text NOT NULL CHECK (length(operation_ref) BETWEEN 1 AND 200),
                writer_ticket_ref text NOT NULL CHECK (length(writer_ticket_ref) BETWEEN 1 AND 200),
                original_request_digest text NOT NULL CHECK (original_request_digest ~ '^sha256:[0-9a-f]{64}$'),
                original_started_at timestamptz NOT NULL,
                window_ends_at timestamptz NOT NULL,
                deadline timestamptz NOT NULL,
                lease_fence bigint NOT NULL CHECK (lease_fence>0),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CHECK (original_started_at<deadline AND deadline<=window_ends_at),
                UNIQUE (installation_ref,seller_ref,environment,intent_owner_namespace,approval_ref),
                UNIQUE (installation_ref,seller_ref,environment,intent_owner_namespace,writer_ticket_ref),
                FOREIGN KEY (installation_ref,seller_ref,environment,attempt_ref)
                    REFERENCES public.billium_execution_attempt(installation_ref,seller_ref,environment,attempt_ref),
                FOREIGN KEY (installation_ref,seller_ref,environment,operation_ref)
                    REFERENCES public.billium_execution_operation(installation_ref,seller_ref,environment,operation_ref)
            );

            CREATE TRIGGER billium_execution_original_replay_immutable BEFORE UPDATE OR DELETE
                ON public.billium_execution_original_replay FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();

            CREATE INDEX billium_execution_original_replay_attempt
                ON public.billium_execution_original_replay(installation_ref,seller_ref,environment,attempt_ref,id);

            CREATE TABLE public.billium_execution_original_identity_import (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK (length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK (length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK (length(environment) BETWEEN 1 AND 200),
                intent_owner_namespace text NOT NULL CHECK (length(intent_owner_namespace) BETWEEN 1 AND 200),
                operation_ref text NOT NULL CHECK (length(operation_ref) BETWEEN 1 AND 200),
                attempt_ref text NOT NULL CHECK (length(attempt_ref) BETWEEN 1 AND 200),
                command_key_digest text NOT NULL CHECK (command_key_digest ~ '^sha256:[0-9a-f]{64}$'),
                input_digest text NOT NULL CHECK (input_digest ~ '^sha256:[0-9a-f]{64}$'),
                manifest_digest text NOT NULL CHECK (manifest_digest ~ '^sha256:[0-9a-f]{64}$'),
                source_journal_digest text NOT NULL CHECK (source_journal_digest ~ '^sha256:[0-9a-f]{64}$'),
                identity_digest text NOT NULL CHECK (identity_digest ~ '^sha256:[0-9a-f]{64}$'),
                original_lineage text NOT NULL CHECK (length(original_lineage) BETWEEN 1 AND 200),
                original_fence bigint NOT NULL CHECK (original_fence>0),
                adopted_epoch bigint NOT NULL CHECK (adopted_epoch>0),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (installation_ref,seller_ref,environment,operation_ref),
                UNIQUE (installation_ref,seller_ref,environment,attempt_ref),
                UNIQUE (installation_ref,seller_ref,environment,intent_owner_namespace,command_key_digest),
                FOREIGN KEY (installation_ref,seller_ref,environment,attempt_ref)
                    REFERENCES public.billium_execution_attempt(installation_ref,seller_ref,environment,attempt_ref),
                FOREIGN KEY (installation_ref,seller_ref,environment,operation_ref)
                    REFERENCES public.billium_execution_operation(installation_ref,seller_ref,environment,operation_ref)
            );

            CREATE TRIGGER billium_execution_original_identity_import_immutable BEFORE UPDATE OR DELETE
                ON public.billium_execution_original_identity_import FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();
            SQL);

        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_recon_binding');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_recon_page');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_recon_item');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_recon_review');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_original_replay');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_original_identity_import');
    }

    public function down(): never
    {
        throw new LogicException('Billium migrations are forward-only.');
    }
};
