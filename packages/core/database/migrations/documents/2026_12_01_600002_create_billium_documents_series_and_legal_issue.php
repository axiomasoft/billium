<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

/**
 * Document series, legal issue and the number journal.
 */
return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_document_series (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                series_ref text NOT NULL,
                document_type text NOT NULL CHECK (document_type IN ('invoice','credit_note','correction')),
                period_key text NOT NULL,
                period_policy text NOT NULL CHECK (period_policy IN ('none','yearly','monthly')),
                timezone text NOT NULL,
                prefix text NOT NULL,
                padding integer NOT NULL CHECK (padding BETWEEN 1 AND 18),
                legal_entity_revision integer NOT NULL CHECK (legal_entity_revision >= 1),
                next_number bigint NOT NULL CHECK (next_number >= 1),
                active boolean NOT NULL DEFAULT true,
                series_digest text NOT NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                UNIQUE (seller_ref, environment, series_ref, period_key)
            );

            CREATE TABLE public.billium_document_legal (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                legal_document_ref text NOT NULL,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                document_type text NOT NULL CHECK (document_type IN ('invoice','credit_note','correction')),
                economic_ref text NOT NULL,
                document_revision integer NOT NULL CHECK (document_revision >= 1),
                series_ref text NOT NULL,
                period_key text NOT NULL,
                sequence_number bigint NOT NULL CHECK (sequence_number >= 1),
                legal_number text NOT NULL,
                status text NOT NULL CHECK (status IN ('issued','voided')),
                profile_id bigint NOT NULL REFERENCES public.billium_document_profile(id),
                profile_version integer NOT NULL CHECK (profile_version >= 1),
                finalization_id bigint NOT NULL REFERENCES public.billium_document_finalization(id),
                basis_revision integer NOT NULL CHECK (basis_revision >= 1),
                basis_digest text NOT NULL,
                approved_snapshot_digest text NOT NULL,
                currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
                subtotal_minor bigint NOT NULL CHECK (subtotal_minor >= 0),
                tax_minor bigint NOT NULL CHECK (tax_minor >= 0),
                total_minor bigint NOT NULL CHECK (subtotal_minor + tax_minor = total_minor),
                seller_snapshot jsonb NOT NULL CHECK (jsonb_typeof(seller_snapshot) = 'object'),
                buyer_snapshot jsonb NOT NULL CHECK (jsonb_typeof(buyer_snapshot) = 'object'),
                tax_snapshot jsonb NOT NULL CHECK (jsonb_typeof(tax_snapshot) = 'object'),
                terms_snapshot jsonb NOT NULL CHECK (jsonb_typeof(terms_snapshot) = 'object'),
                lines_snapshot jsonb NOT NULL CHECK (jsonb_typeof(lines_snapshot) = 'array'),
                display_metadata jsonb NOT NULL CHECK (jsonb_typeof(display_metadata) = 'object'),
                original_legal_document_ref text NULL,
                void_reason text NULL,
                voided_at timestamptz NULL,
                receipt_ref text NOT NULL,
                issued_at timestamptz NOT NULL,
                UNIQUE (seller_ref, environment, legal_document_ref),
                UNIQUE (seller_ref, environment, series_ref, period_key, sequence_number),
                UNIQUE (seller_ref, environment, legal_number),
                UNIQUE (seller_ref, environment, document_type, economic_ref, document_revision),
                CHECK (
                    (status = 'issued' AND void_reason IS NULL AND voided_at IS NULL)
                    OR (status = 'voided' AND void_reason IS NOT NULL AND length(btrim(void_reason)) > 0 AND voided_at IS NOT NULL)
                )
            );

            CREATE TABLE public.billium_document_number_journal (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                series_ref text NOT NULL,
                period_key text NOT NULL,
                sequence_number bigint NOT NULL CHECK (sequence_number >= 1),
                legal_number text NOT NULL,
                legal_document_ref text NOT NULL,
                event_kind text NOT NULL CHECK (event_kind IN ('allocated','voided')),
                reason text NULL,
                occurred_at timestamptz NOT NULL
            );

            CREATE TRIGGER billium_document_number_journal_immutable
                BEFORE UPDATE OR DELETE ON public.billium_document_number_journal
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE OR REPLACE FUNCTION public.billium_documents_series_guard()
            RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium_document_series cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF (NEW.seller_ref, NEW.environment, NEW.series_ref, NEW.document_type, NEW.period_key, NEW.period_policy,
                    NEW.prefix, NEW.padding, NEW.legal_entity_revision, NEW.series_digest)
                   IS DISTINCT FROM
                   (OLD.seller_ref, OLD.environment, OLD.series_ref, OLD.document_type, OLD.period_key, OLD.period_policy,
                    OLD.prefix, OLD.padding, OLD.legal_entity_revision, OLD.series_digest) THEN
                    RAISE EXCEPTION 'billium_document_series definition is immutable' USING ERRCODE = '23514';
                END IF;
                IF NEW.next_number < OLD.next_number THEN
                    RAISE EXCEPTION 'billium_document_series next_number cannot decrease' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_documents_series_guard() FROM PUBLIC;

            CREATE TRIGGER billium_document_series_guard_trg
                BEFORE UPDATE OR DELETE ON public.billium_document_series
                FOR EACH ROW EXECUTE FUNCTION public.billium_documents_series_guard();

            CREATE OR REPLACE FUNCTION public.billium_documents_legal_immutable()
            RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium_document_legal cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF (NEW.legal_document_ref, NEW.seller_ref, NEW.environment, NEW.document_type, NEW.economic_ref, NEW.document_revision,
                    NEW.series_ref, NEW.period_key, NEW.sequence_number, NEW.legal_number, NEW.profile_id, NEW.profile_version,
                    NEW.finalization_id, NEW.basis_revision, NEW.basis_digest, NEW.approved_snapshot_digest, NEW.currency,
                    NEW.subtotal_minor, NEW.tax_minor, NEW.total_minor, NEW.seller_snapshot, NEW.buyer_snapshot, NEW.tax_snapshot,
                    NEW.terms_snapshot, NEW.lines_snapshot, NEW.original_legal_document_ref, NEW.receipt_ref, NEW.issued_at)
                   IS DISTINCT FROM
                   (OLD.legal_document_ref, OLD.seller_ref, OLD.environment, OLD.document_type, OLD.economic_ref, OLD.document_revision,
                    OLD.series_ref, OLD.period_key, OLD.sequence_number, OLD.legal_number, OLD.profile_id, OLD.profile_version,
                    OLD.finalization_id, OLD.basis_revision, OLD.basis_digest, OLD.approved_snapshot_digest, OLD.currency,
                    OLD.subtotal_minor, OLD.tax_minor, OLD.total_minor, OLD.seller_snapshot, OLD.buyer_snapshot, OLD.tax_snapshot,
                    OLD.terms_snapshot, OLD.lines_snapshot, OLD.original_legal_document_ref, OLD.receipt_ref, OLD.issued_at) THEN
                    RAISE EXCEPTION 'billium_document_legal frozen snapshots and numbers are immutable' USING ERRCODE = '23514';
                END IF;
                IF OLD.status = 'voided' AND (NEW.status, NEW.void_reason, NEW.voided_at) IS DISTINCT FROM (OLD.status, OLD.void_reason, OLD.voided_at) THEN
                    RAISE EXCEPTION 'voided legal document cannot change status or void details' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_documents_legal_immutable() FROM PUBLIC;

            CREATE TRIGGER billium_document_legal_immutable_trg
                BEFORE UPDATE OR DELETE ON public.billium_document_legal
                FOR EACH ROW EXECUTE FUNCTION public.billium_documents_legal_immutable();
            SQL);

        RuntimeGrants::grant('SELECT, INSERT, UPDATE', 'public.billium_document_series');
        RuntimeGrants::grant('SELECT, INSERT, UPDATE', 'public.billium_document_legal');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_document_number_journal');
    }

    public function down(): never
    {
        throw new LogicException('Billium migrations are forward-only.');
    }
};
