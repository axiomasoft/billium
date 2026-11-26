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
                FOREIGN KEY (run_id,page_no) REFERENCES public.billium_execution_recon_page(run_id,page_no) DEFERRABLE INITIALLY DEFERRED
            );
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
                CHECK(status<>'resolved' OR (receipt_ref IS NOT NULL AND next_review_at IS NULL))
            );
            CREATE INDEX billium_execution_recon_review_history ON public.billium_execution_recon_review (installation_ref,seller_ref,environment,owner_namespace,source_authority,case_ref,id DESC);
            CREATE INDEX billium_execution_recon_review_open ON public.billium_execution_recon_review (installation_ref,seller_ref,environment,owner_namespace,source_authority,status,created_at,id);
            CREATE INDEX billium_execution_recon_review_due ON public.billium_execution_recon_review (installation_ref,seller_ref,environment,owner_namespace,source_authority,status,next_review_at,id);
            CREATE TRIGGER billium_execution_recon_binding_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_recon_binding FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();
            CREATE TRIGGER billium_execution_recon_page_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_recon_page FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();
            CREATE TRIGGER billium_execution_recon_item_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_recon_item FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();
            CREATE TRIGGER billium_execution_recon_review_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_recon_review FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();
            SQL);
        foreach (['recon_binding', 'recon_page', 'recon_item', 'recon_review'] as $suffix) {
            RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_'.$suffix);
        }
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
