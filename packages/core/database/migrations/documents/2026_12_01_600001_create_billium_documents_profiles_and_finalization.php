<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

/**
 * Document profiles and finalization.
 */
return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_document_profile (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                profile_ref text NOT NULL,
                version integer NOT NULL CHECK (version >= 1),
                jurisdiction_ref text NOT NULL,
                supported_types jsonb NOT NULL CHECK (jsonb_typeof(supported_types) = 'array'),
                numbering_policy jsonb NOT NULL CHECK (jsonb_typeof(numbering_policy) = 'object'),
                tax_snapshot_schema text NOT NULL,
                correction_policy jsonb NOT NULL CHECK (jsonb_typeof(correction_policy) = 'object'),
                required_fields jsonb NOT NULL CHECK (jsonb_typeof(required_fields) = 'array'),
                retention_policy_ref text NOT NULL,
                seller_snapshot jsonb NOT NULL CHECK (jsonb_typeof(seller_snapshot) = 'object'),
                is_complete boolean NOT NULL,
                profile_digest text NOT NULL,
                created_at timestamptz NOT NULL,
                UNIQUE (seller_ref, environment, profile_ref, version)
            );

            CREATE TRIGGER billium_document_profile_immutable
                BEFORE UPDATE OR DELETE ON public.billium_document_profile
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE TABLE public.billium_document_finalization (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                finalization_ref text NOT NULL,
                document_type text NOT NULL CHECK (document_type IN ('invoice','credit_note','correction')),
                economic_ref text NOT NULL,
                basis_revision integer NOT NULL CHECK (basis_revision >= 1),
                basis_digest text NOT NULL,
                profile_id bigint NOT NULL REFERENCES public.billium_document_profile(id),
                profile_ref text NOT NULL,
                profile_version integer NOT NULL CHECK (profile_version >= 1),
                finalization_revision integer NOT NULL CHECK (finalization_revision >= 1),
                state text NOT NULL CHECK (state IN ('draft','finalizing','snapshot_ready','superseded')),
                currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
                currency_exponent integer NOT NULL CHECK (currency_exponent BETWEEN 0 AND 4),
                tax_convention text NOT NULL CHECK (tax_convention IN ('exclusive','inclusive','none_declared')),
                rounding_mode text NOT NULL CHECK (rounding_mode IN ('down','up','half_up','half_even')),
                discount_tax_order text NOT NULL CHECK (discount_tax_order IN ('discount_before_tax','tax_before_discount')),
                input_digest text NOT NULL,
                buyer_snapshot jsonb NOT NULL CHECK (jsonb_typeof(buyer_snapshot) = 'object'),
                terms_snapshot jsonb NOT NULL CHECK (jsonb_typeof(terms_snapshot) = 'object'),
                lines_snapshot jsonb NOT NULL CHECK (jsonb_typeof(lines_snapshot) = 'array'),
                tax_snapshot_ref text NULL,
                tax_snapshot_digest text NULL,
                subtotal_minor bigint NULL,
                tax_minor bigint NULL,
                total_minor bigint NULL,
                approved_snapshot_digest text NULL,
                tax_snapshot_payload jsonb NULL CHECK (tax_snapshot_payload IS NULL OR jsonb_typeof(tax_snapshot_payload) = 'object'),
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                UNIQUE (seller_ref, environment, finalization_ref, finalization_revision),
                CHECK (
                    state <> 'snapshot_ready'
                    OR (
                        tax_snapshot_ref IS NOT NULL
                        AND tax_snapshot_digest IS NOT NULL
                        AND subtotal_minor IS NOT NULL
                        AND tax_minor IS NOT NULL
                        AND total_minor IS NOT NULL
                        AND approved_snapshot_digest IS NOT NULL
                        AND tax_snapshot_payload IS NOT NULL
                        AND subtotal_minor >= 0
                        AND tax_minor >= 0
                        AND subtotal_minor + tax_minor = total_minor
                    )
                )
            );

            CREATE UNIQUE INDEX billium_document_finalization_active_uidx
                ON public.billium_document_finalization (seller_ref, environment, finalization_ref)
                WHERE state <> 'superseded';

            CREATE TABLE public.billium_document_finalization_event (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                finalization_id bigint NOT NULL REFERENCES public.billium_document_finalization(id),
                finalization_revision integer NOT NULL CHECK (finalization_revision >= 1),
                event_kind text NOT NULL CHECK (event_kind IN ('draft_created','finalization_started','revision_superseded','snapshot_attached','stale_snapshot_ignored','tax_port_failed')),
                input_digest text NOT NULL,
                details jsonb NOT NULL CHECK (jsonb_typeof(details) = 'object'),
                occurred_at timestamptz NOT NULL
            );

            CREATE TRIGGER billium_document_finalization_event_immutable
                BEFORE UPDATE OR DELETE ON public.billium_document_finalization_event
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE OR REPLACE FUNCTION public.billium_documents_finalization_guard()
            RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium_document_finalization cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF OLD.state IN ('snapshot_ready', 'superseded') THEN
                    RAISE EXCEPTION 'billium_document_finalization is immutable once %', OLD.state USING ERRCODE = '23514';
                END IF;
                IF (NEW.seller_ref, NEW.environment, NEW.finalization_ref, NEW.document_type, NEW.economic_ref, NEW.finalization_revision, NEW.input_digest)
                   IS DISTINCT FROM
                   (OLD.seller_ref, OLD.environment, OLD.finalization_ref, OLD.document_type, OLD.economic_ref, OLD.finalization_revision, OLD.input_digest) THEN
                    RAISE EXCEPTION 'billium_document_finalization identity and input_digest are immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_documents_finalization_guard() FROM PUBLIC;

            CREATE TRIGGER billium_document_finalization_guard_trg
                BEFORE UPDATE OR DELETE ON public.billium_document_finalization
                FOR EACH ROW EXECUTE FUNCTION public.billium_documents_finalization_guard();
            SQL);

        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_document_profile');
        RuntimeGrants::grant('SELECT, INSERT, UPDATE', 'public.billium_document_finalization');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_document_finalization_event');
    }

    public function down(): never
    {
        throw new LogicException('Billium migrations are forward-only.');
    }
};
