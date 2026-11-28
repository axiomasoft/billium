<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

/**
 * Document corrections: corrections and credit note lines.
 */
return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_document_correction (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                correction_ref text NOT NULL,
                correction_kind text NOT NULL CHECK (correction_kind IN ('credit_note','document_correction','void_reissue','write_off')),
                original_legal_document_id bigint NOT NULL REFERENCES public.billium_document_legal(id),
                original_legal_document_ref text NOT NULL,
                resulting_legal_document_id bigint NULL REFERENCES public.billium_document_legal(id),
                resulting_legal_document_ref text NULL,
                ledger_credit_note_ref text NULL,
                ledger_obligation_ref text NULL,
                parent_occurrence_ref text NULL,
                correction_revision integer NOT NULL CHECK (correction_revision >= 1),
                tax_correction_policy_ref text NOT NULL,
                tax_correction_policy_version text NOT NULL,
                tax_treatment text NOT NULL CHECK (tax_treatment IN ('original_rate','current_legal_rate','other_supported')),
                tax_policy_snapshot jsonb NOT NULL CHECK (jsonb_typeof(tax_policy_snapshot) = 'object'),
                currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
                net_minor bigint NOT NULL CHECK (net_minor >= 0),
                tax_minor bigint NOT NULL CHECK (tax_minor >= 0),
                gross_minor bigint NOT NULL CHECK (gross_minor >= 0 AND net_minor + tax_minor = gross_minor),
                reason text NOT NULL CHECK (length(btrim(reason)) > 0),
                allocation_mode text NULL CHECK (allocation_mode IS NULL OR allocation_mode IN ('open_only','reverse_allocations')),
                receipt_ref text NOT NULL,
                created_at timestamptz NOT NULL,
                UNIQUE (seller_ref, environment, correction_ref)
            );

            CREATE TRIGGER billium_document_correction_immutable
                BEFORE UPDATE OR DELETE ON public.billium_document_correction
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE TABLE public.billium_document_credit_note_line (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                credit_note_id bigint NOT NULL REFERENCES public.billium_document_correction(id),
                original_legal_document_id bigint NOT NULL REFERENCES public.billium_document_legal(id),
                original_invoice_line_id text NOT NULL,
                original_obligation_ref text NOT NULL,
                original_component_ref text NOT NULL,
                original_tax_snapshot_ref text NOT NULL,
                original_rounding_policy_version text NOT NULL,
                credited_quantity text NOT NULL,
                net_minor bigint NOT NULL CHECK (net_minor >= 0),
                tax_minor bigint NOT NULL CHECK (tax_minor >= 0),
                gross_minor bigint NOT NULL CHECK (gross_minor > 0 AND net_minor + tax_minor = gross_minor),
                currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
                cumulative_net_minor bigint NOT NULL CHECK (cumulative_net_minor >= net_minor),
                cumulative_tax_minor bigint NOT NULL CHECK (cumulative_tax_minor >= tax_minor),
                cumulative_gross_minor bigint NOT NULL CHECK (cumulative_gross_minor >= gross_minor AND cumulative_net_minor + cumulative_tax_minor = cumulative_gross_minor),
                original_basis_net_minor bigint NOT NULL CHECK (original_basis_net_minor >= 0),
                original_basis_tax_minor bigint NOT NULL CHECK (original_basis_tax_minor >= 0),
                original_basis_gross_minor bigint NOT NULL CHECK (original_basis_gross_minor > 0 AND cumulative_gross_minor <= original_basis_gross_minor),
                created_at timestamptz NOT NULL,
                UNIQUE (seller_ref, environment, credit_note_id, original_invoice_line_id)
            );

            CREATE TRIGGER billium_document_credit_note_line_immutable
                BEFORE UPDATE OR DELETE ON public.billium_document_credit_note_line
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE OR REPLACE FUNCTION public.billium_documents_check_credit_note_sums()
            RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                v_corr public.billium_document_correction;
                v_sum_net bigint;
                v_sum_tax bigint;
                v_sum_gross bigint;
                v_cum_net bigint;
                v_cum_tax bigint;
                v_cum_gross bigint;
                v_orig public.billium_document_legal;
            BEGIN
                IF TG_TABLE_NAME = 'billium_document_correction' THEN
                    IF NEW.correction_kind <> 'credit_note' THEN
                        RETURN NULL;
                    END IF;
                    SELECT COALESCE(SUM(net_minor), 0)::bigint, COALESCE(SUM(tax_minor), 0)::bigint, COALESCE(SUM(gross_minor), 0)::bigint
                      INTO v_sum_net, v_sum_tax, v_sum_gross
                      FROM public.billium_document_credit_note_line
                     WHERE credit_note_id = NEW.id;
                    IF v_sum_gross <= 0 OR v_sum_net <> NEW.net_minor OR v_sum_tax <> NEW.tax_minor OR v_sum_gross <> NEW.gross_minor THEN
                        RAISE EXCEPTION 'credit note line totals must equal header totals' USING ERRCODE = '23514';
                    END IF;
                    RETURN NULL;
                END IF;

                SELECT * INTO v_corr FROM public.billium_document_correction WHERE id = NEW.credit_note_id;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'credit note header missing' USING ERRCODE = '23514';
                END IF;
                SELECT * INTO v_orig FROM public.billium_document_legal WHERE id = NEW.original_legal_document_id;
                IF NOT FOUND OR v_corr.seller_ref <> NEW.seller_ref OR v_corr.environment <> NEW.environment OR v_orig.seller_ref <> NEW.seller_ref OR v_orig.environment <> NEW.environment THEN
                    RAISE EXCEPTION 'credit note line scope mismatch with correction/original document' USING ERRCODE = '23514';
                END IF;

                SELECT COALESCE(SUM(net_minor), 0)::bigint, COALESCE(SUM(tax_minor), 0)::bigint, COALESCE(SUM(gross_minor), 0)::bigint
                  INTO v_cum_net, v_cum_tax, v_cum_gross
                  FROM public.billium_document_credit_note_line
                 WHERE seller_ref = NEW.seller_ref
                   AND environment = NEW.environment
                   AND original_legal_document_id = NEW.original_legal_document_id
                   AND original_invoice_line_id = NEW.original_invoice_line_id;

                IF v_cum_gross > NEW.original_basis_gross_minor THEN
                    RAISE EXCEPTION 'cumulative credit note gross exceeds original basis' USING ERRCODE = '23514';
                END IF;
                IF v_corr.tax_treatment = 'original_rate' AND (v_cum_net > NEW.original_basis_net_minor OR v_cum_tax > NEW.original_basis_tax_minor) THEN
                    RAISE EXCEPTION 'cumulative credit note net/tax under original_rate exceeds original basis' USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_documents_check_credit_note_sums() FROM PUBLIC;

            CREATE CONSTRAINT TRIGGER billium_document_correction_sums_trg
                AFTER INSERT ON public.billium_document_correction
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.billium_documents_check_credit_note_sums();

            CREATE CONSTRAINT TRIGGER billium_document_credit_note_line_sums_trg
                AFTER INSERT ON public.billium_document_credit_note_line
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.billium_documents_check_credit_note_sums();

            -- A document correction sums the prior credit note lines of its original invoice before it caps the new credit.
            -- Without an index that read walked every credit note line of the seller inside the correction transaction.
            CREATE INDEX billium_document_credit_note_line_original_idx
                ON public.billium_document_credit_note_line (seller_ref, environment, original_legal_document_id);
            SQL);

        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_document_correction');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_document_credit_note_line');
    }

    public function down(): never
    {
        throw new LogicException('Billium migrations are forward-only.');
    }
};
