<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        // D71/P9.1: platform-owned external import staging, source identity mappings and immutable audit receipts.
        // Source identity is scoped by (installation_ref, environment, seller_ref, source_installation, source_book,
        // source_record_id, record_kind); batch_id and import_policy_version are never part of economic identity.
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_import_batch (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
                environment text NOT NULL CHECK (environment <> '' AND length(environment) <= 200),
                seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
                batch_id text NOT NULL CHECK (batch_id <> '' AND length(batch_id) <= 200),
                source_system text NOT NULL CHECK (source_system ~ '^[a-z][a-z0-9_.-]{0,63}(/[a-z0-9_.-]{1,64})*$' AND length(source_system) <= 200),
                source_installation text NOT NULL CHECK (source_installation <> '' AND length(source_installation) <= 200),
                source_book text NOT NULL CHECK (source_book <> '' AND length(source_book) <= 200),
                source_export_revision bigint NOT NULL CHECK (source_export_revision >= 0),
                export_cutoff timestamptz NOT NULL,
                manifest_digest text NOT NULL CHECK (manifest_digest ~ '^sha256:[0-9a-f]{64}$'),
                records_checksum text NOT NULL CHECK (records_checksum ~ '^sha256:[0-9a-f]{64}$'),
                record_count integer NOT NULL CHECK (record_count >= 0),
                import_policy_version text NULL CHECK (import_policy_version IS NULL OR (import_policy_version <> '' AND length(import_policy_version) <= 200)),
                status text NOT NULL CHECK (status IN ('staged', 'validated', 'approved', 'applying', 'reconciled', 'completed', 'rejected', 'quarantined')),
                approval_ref text NULL CHECK (approval_ref IS NULL OR (approval_ref <> '' AND length(approval_ref) <= 200)),
                authority_fence_ref text NULL CHECK (authority_fence_ref IS NULL OR (authority_fence_ref <> '' AND length(authority_fence_ref) <= 200)),
                sanitization_policy jsonb NOT NULL CHECK (jsonb_typeof(sanitization_policy) = 'object' AND octet_length(sanitization_policy::text) <= 16384),
                manifest_payload jsonb NOT NULL CHECK (jsonb_typeof(manifest_payload) = 'object' AND octet_length(manifest_payload::text) <= 65536),
                staged_at timestamptz NOT NULL DEFAULT now(),
                validated_at timestamptz NULL,
                approved_at timestamptz NULL,
                completed_at timestamptz NULL,
                rejected_at timestamptz NULL,
                CONSTRAINT billium_import_batch_scope_id_key UNIQUE (id, installation_ref, environment, seller_ref),
                CONSTRAINT billium_import_batch_identity_key UNIQUE (installation_ref, environment, seller_ref, batch_id)
            );
            CREATE INDEX billium_import_batch_scope_status_idx
                ON public.billium_import_batch (installation_ref, environment, seller_ref, status, staged_at);
            CREATE INDEX billium_import_batch_source_rev_idx
                ON public.billium_import_batch (installation_ref, environment, seller_ref, source_installation, source_book, source_export_revision);

            CREATE TABLE public.billium_import_record (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                batch_pk bigint NOT NULL,
                installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
                environment text NOT NULL CHECK (environment <> '' AND length(environment) <= 200),
                seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
                source_system text NOT NULL CHECK (source_system ~ '^[a-z][a-z0-9_.-]{0,63}(/[a-z0-9_.-]{1,64})*$' AND length(source_system) <= 200),
                source_installation text NOT NULL CHECK (source_installation <> '' AND length(source_installation) <= 200),
                source_book text NOT NULL CHECK (source_book <> '' AND length(source_book) <= 200),
                source_record_id text NOT NULL CHECK (source_record_id <> '' AND length(source_record_id) <= 200),
                source_record_revision bigint NOT NULL CHECK (source_record_revision >= 0),
                record_kind text NOT NULL CHECK (record_kind IN (
                    'proven_obligation',
                    'proven_invoice_history',
                    'proven_payment_evidence',
                    'proven_refund_evidence',
                    'proven_allocation',
                    'nonfinancial_agreement_reference',
                    'unanchored_evidence'
                )),
                source_identity_digest text NOT NULL CHECK (source_identity_digest ~ '^sha256:[0-9a-f]{64}$'),
                payload_digest text NOT NULL CHECK (payload_digest ~ '^sha256:[0-9a-f]{64}$'),
                event_business_date timestamptz NOT NULL,
                currency text NULL CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$'),
                amount_minor bigint NULL,
                source_fact_ref text NULL CHECK (source_fact_ref IS NULL OR (source_fact_ref <> '' AND length(source_fact_ref) <= 256)),
                import_policy_version text NULL CHECK (import_policy_version IS NULL OR (import_policy_version <> '' AND length(import_policy_version) <= 200)),
                validation_status text NOT NULL CHECK (validation_status IN ('ready', 'unanchored_evidence', 'rejected', 'conflict', 'already_applied')),
                reason_codes jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(reason_codes) = 'array' AND octet_length(reason_codes::text) <= 16384),
                missing_evidence jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(missing_evidence) = 'array' AND octet_length(missing_evidence::text) <= 16384),
                rejection_code text NULL CHECK (rejection_code IS NULL OR (rejection_code <> '' AND length(rejection_code) <= 200)),
                payload jsonb NOT NULL CHECK (jsonb_typeof(payload) = 'object' AND octet_length(payload::text) <= 65536),
                staged_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT billium_import_record_scope_id_key UNIQUE (id, installation_ref, environment, seller_ref),
                CONSTRAINT billium_import_record_batch_fk
                    FOREIGN KEY (batch_pk, installation_ref, environment, seller_ref)
                    REFERENCES public.billium_import_batch (id, installation_ref, environment, seller_ref),
                CONSTRAINT billium_import_record_batch_identity_key UNIQUE (
                    batch_pk, installation_ref, environment, seller_ref,
                    source_installation, source_book, source_record_id, record_kind, source_record_revision
                ),
                CONSTRAINT billium_import_record_money_pair_check CHECK ((currency IS NULL) = (amount_minor IS NULL)),
                CONSTRAINT billium_import_record_rejection_check CHECK (validation_status <> 'rejected' OR rejection_code IS NOT NULL)
            );
            CREATE INDEX billium_import_record_source_identity_idx
                ON public.billium_import_record (installation_ref, environment, seller_ref, source_installation, source_book, source_record_id, record_kind, source_record_revision);
            CREATE INDEX billium_import_record_batch_summary_idx
                ON public.billium_import_record (batch_pk, validation_status, currency, record_kind);

            CREATE TABLE public.billium_import_mapping (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                mapping_ref text NOT NULL CHECK (mapping_ref <> '' AND length(mapping_ref) <= 200),
                installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
                environment text NOT NULL CHECK (environment <> '' AND length(environment) <= 200),
                seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
                source_system text NOT NULL CHECK (source_system ~ '^[a-z][a-z0-9_.-]{0,63}(/[a-z0-9_.-]{1,64})*$' AND length(source_system) <= 200),
                source_installation text NOT NULL CHECK (source_installation <> '' AND length(source_installation) <= 200),
                source_book text NOT NULL CHECK (source_book <> '' AND length(source_book) <= 200),
                source_record_id text NOT NULL CHECK (source_record_id <> '' AND length(source_record_id) <= 200),
                record_kind text NOT NULL CHECK (record_kind IN (
                    'proven_obligation',
                    'proven_invoice_history',
                    'proven_payment_evidence',
                    'proven_refund_evidence',
                    'proven_allocation',
                    'nonfinancial_agreement_reference',
                    'unanchored_evidence'
                )),
                source_record_revision bigint NOT NULL CHECK (source_record_revision >= 0),
                source_identity_digest text NOT NULL CHECK (source_identity_digest ~ '^sha256:[0-9a-f]{64}$'),
                payload_digest text NOT NULL CHECK (payload_digest ~ '^sha256:[0-9a-f]{64}$'),
                source_fact_ref text NOT NULL CHECK (source_fact_ref <> '' AND length(source_fact_ref) <= 256),
                billium_target_kind text NULL CHECK (billium_target_kind IS NULL OR (billium_target_kind <> '' AND length(billium_target_kind) <= 100)),
                billium_target_ref text NULL CHECK (billium_target_ref IS NULL OR (billium_target_ref <> '' AND length(billium_target_ref) <= 200)),
                original_issuer text NULL CHECK (original_issuer IS NULL OR (original_issuer <> '' AND length(original_issuer) <= 200)),
                original_source_number text NULL CHECK (original_source_number IS NULL OR (original_source_number <> '' AND length(original_source_number) <= 200)),
                import_policy_version text NULL CHECK (import_policy_version IS NULL OR (import_policy_version <> '' AND length(import_policy_version) <= 200)),
                status text NOT NULL CHECK (status IN ('active', 'superseded', 'tombstoned', 'quarantined')),
                tombstone_reason text NULL CHECK (tombstone_reason IS NULL OR (tombstone_reason <> '' AND length(tombstone_reason) <= 200)),
                tombstoned_at timestamptz NULL,
                superseded_by_revision bigint NULL CHECK (superseded_by_revision IS NULL OR superseded_by_revision > source_record_revision),
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT billium_import_mapping_scope_id_key UNIQUE (id, installation_ref, environment, seller_ref),
                CONSTRAINT billium_import_mapping_ref_key UNIQUE (installation_ref, environment, seller_ref, mapping_ref),
                CONSTRAINT billium_import_mapping_revision_key UNIQUE (
                    installation_ref, environment, seller_ref,
                    source_installation, source_book, source_record_id, record_kind, source_record_revision
                ),
                CONSTRAINT billium_import_mapping_source_fact_key UNIQUE (installation_ref, environment, seller_ref, source_fact_ref),
                CONSTRAINT billium_import_mapping_tombstone_check CHECK (
                    (status = 'tombstoned') = (tombstoned_at IS NOT NULL AND tombstone_reason IS NOT NULL)
                ),
                CONSTRAINT billium_import_mapping_superseded_check CHECK (
                    (status = 'superseded') = (superseded_by_revision IS NOT NULL)
                ),
                CONSTRAINT billium_import_mapping_target_pair_check CHECK (
                    (billium_target_kind IS NULL) = (billium_target_ref IS NULL)
                )
            );
            CREATE UNIQUE INDEX billium_import_mapping_active_identity_key
                ON public.billium_import_mapping (installation_ref, environment, seller_ref, source_installation, source_book, source_record_id, record_kind)
                WHERE status = 'active';
            CREATE INDEX billium_import_mapping_identity_digest_idx
                ON public.billium_import_mapping (installation_ref, environment, seller_ref, source_identity_digest, source_record_revision);

            CREATE TABLE public.billium_import_receipt (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                receipt_ref text NOT NULL CHECK (receipt_ref <> '' AND length(receipt_ref) <= 200),
                installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
                environment text NOT NULL CHECK (environment <> '' AND length(environment) <= 200),
                seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
                batch_pk bigint NOT NULL,
                record_pk bigint NULL,
                mapping_pk bigint NULL,
                source_identity_digest text NOT NULL CHECK (source_identity_digest ~ '^sha256:[0-9a-f]{64}$'),
                source_record_revision bigint NOT NULL CHECK (source_record_revision >= 0),
                payload_digest text NOT NULL CHECK (payload_digest ~ '^sha256:[0-9a-f]{64}$'),
                source_fact_ref text NULL CHECK (source_fact_ref IS NULL OR (source_fact_ref <> '' AND length(source_fact_ref) <= 256)),
                status text NOT NULL CHECK (status IN ('staged', 'applied', 'observation_queued', 'unanchored_evidence', 'rejected', 'conflict', 'tombstoned')),
                billium_receipt_ref text NULL CHECK (billium_receipt_ref IS NULL OR (billium_receipt_ref <> '' AND length(billium_receipt_ref) <= 200)),
                details jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(details) = 'object' AND octet_length(details::text) <= 65536),
                recorded_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT billium_import_receipt_ref_key UNIQUE (installation_ref, environment, seller_ref, receipt_ref),
                CONSTRAINT billium_import_receipt_batch_fk
                    FOREIGN KEY (batch_pk, installation_ref, environment, seller_ref)
                    REFERENCES public.billium_import_batch (id, installation_ref, environment, seller_ref),
                CONSTRAINT billium_import_receipt_record_fk
                    FOREIGN KEY (record_pk, installation_ref, environment, seller_ref)
                    REFERENCES public.billium_import_record (id, installation_ref, environment, seller_ref),
                CONSTRAINT billium_import_receipt_mapping_fk
                    FOREIGN KEY (mapping_pk, installation_ref, environment, seller_ref)
                    REFERENCES public.billium_import_mapping (id, installation_ref, environment, seller_ref)
            );
            CREATE UNIQUE INDEX billium_import_receipt_applied_once_key
                ON public.billium_import_receipt (installation_ref, environment, seller_ref, source_identity_digest, source_record_revision)
                WHERE status IN ('applied', 'observation_queued');
            CREATE INDEX billium_import_receipt_batch_idx
                ON public.billium_import_receipt (batch_pk, status, recorded_at);

            CREATE OR REPLACE FUNCTION public.billium_import_batch_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium: import batches are never deleted by runtime' USING ERRCODE = '55000';
                END IF;
                IF (NEW.id, NEW.installation_ref, NEW.environment, NEW.seller_ref, NEW.batch_id,
                    NEW.source_system, NEW.source_installation, NEW.source_book, NEW.source_export_revision,
                    NEW.export_cutoff, NEW.manifest_digest, NEW.records_checksum, NEW.record_count,
                    NEW.sanitization_policy, NEW.manifest_payload, NEW.staged_at)
                   IS DISTINCT FROM
                   (OLD.id, OLD.installation_ref, OLD.environment, OLD.seller_ref, OLD.batch_id,
                    OLD.source_system, OLD.source_installation, OLD.source_book, OLD.source_export_revision,
                    OLD.export_cutoff, OLD.manifest_digest, OLD.records_checksum, OLD.record_count,
                    OLD.sanitization_policy, OLD.manifest_payload, OLD.staged_at) THEN
                    RAISE EXCEPTION 'billium: import batch manifest, cutoff and checksum are immutable' USING ERRCODE = '55000';
                END IF;
                IF OLD.status IN ('completed', 'rejected', 'quarantined') AND NEW.status IS DISTINCT FROM OLD.status THEN
                    RAISE EXCEPTION 'billium: terminal import batch status % cannot transition to %', OLD.status, NEW.status USING ERRCODE = '55000';
                END IF;
                IF NEW.status IS DISTINCT FROM OLD.status THEN
                    IF NOT (
                        (OLD.status = 'staged' AND NEW.status IN ('validated', 'rejected', 'quarantined'))
                        OR (OLD.status = 'validated' AND NEW.status IN ('approved', 'rejected', 'quarantined'))
                        OR (OLD.status = 'approved' AND NEW.status IN ('applying', 'rejected', 'quarantined'))
                        OR (OLD.status = 'applying' AND NEW.status IN ('reconciled', 'quarantined'))
                        OR (OLD.status = 'reconciled' AND NEW.status IN ('completed', 'quarantined'))
                    ) THEN
                        RAISE EXCEPTION 'billium: invalid import batch transition % -> %', OLD.status, NEW.status USING ERRCODE = '55000';
                    END IF;
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_import_batch_guard() FROM PUBLIC;
            CREATE TRIGGER billium_import_batch_guard BEFORE UPDATE OR DELETE ON public.billium_import_batch
                FOR EACH ROW EXECUTE FUNCTION public.billium_import_batch_guard();

            CREATE OR REPLACE FUNCTION public.billium_import_record_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium: import records are never deleted by runtime' USING ERRCODE = '55000';
                END IF;
                IF (NEW.id, NEW.batch_pk, NEW.installation_ref, NEW.environment, NEW.seller_ref,
                    NEW.source_system, NEW.source_installation, NEW.source_book, NEW.source_record_id,
                    NEW.source_record_revision, NEW.record_kind, NEW.source_identity_digest,
                    NEW.payload_digest, NEW.event_business_date, NEW.currency, NEW.amount_minor,
                    NEW.import_policy_version, NEW.payload, NEW.staged_at)
                   IS DISTINCT FROM
                   (OLD.id, OLD.batch_pk, OLD.installation_ref, OLD.environment, OLD.seller_ref,
                    OLD.source_system, OLD.source_installation, OLD.source_book, OLD.source_record_id,
                    OLD.source_record_revision, OLD.record_kind, OLD.source_identity_digest,
                    OLD.payload_digest, OLD.event_business_date, OLD.currency, OLD.amount_minor,
                    OLD.import_policy_version, OLD.payload, OLD.staged_at) THEN
                    RAISE EXCEPTION 'billium: staged import record identity and payload are immutable' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_import_record_guard() FROM PUBLIC;
            CREATE TRIGGER billium_import_record_guard BEFORE UPDATE OR DELETE ON public.billium_import_record
                FOR EACH ROW EXECUTE FUNCTION public.billium_import_record_guard();

            CREATE OR REPLACE FUNCTION public.billium_import_mapping_insert_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                IF NEW.status = 'active' AND EXISTS (
                    SELECT 1
                    FROM public.billium_import_mapping m
                    WHERE m.installation_ref = NEW.installation_ref
                      AND m.environment = NEW.environment
                      AND m.seller_ref = NEW.seller_ref
                      AND m.source_installation = NEW.source_installation
                      AND m.source_book = NEW.source_book
                      AND m.source_record_id = NEW.source_record_id
                      AND m.record_kind = NEW.record_kind
                      AND m.status = 'tombstoned'
                ) THEN
                    RAISE EXCEPTION 'billium: tombstoned source identity cannot be reactivated silently' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_import_mapping_insert_guard() FROM PUBLIC;
            CREATE TRIGGER billium_import_mapping_insert_guard BEFORE INSERT ON public.billium_import_mapping
                FOR EACH ROW EXECUTE FUNCTION public.billium_import_mapping_insert_guard();

            CREATE OR REPLACE FUNCTION public.billium_import_mapping_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium: import mappings and tombstones are never deleted by runtime' USING ERRCODE = '55000';
                END IF;
                IF (NEW.id, NEW.mapping_ref, NEW.installation_ref, NEW.environment, NEW.seller_ref,
                    NEW.source_system, NEW.source_installation, NEW.source_book, NEW.source_record_id,
                    NEW.record_kind, NEW.source_record_revision, NEW.source_identity_digest,
                    NEW.payload_digest, NEW.source_fact_ref, NEW.original_issuer,
                    NEW.original_source_number, NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.id, OLD.mapping_ref, OLD.installation_ref, OLD.environment, OLD.seller_ref,
                    OLD.source_system, OLD.source_installation, OLD.source_book, OLD.source_record_id,
                    OLD.record_kind, OLD.source_record_revision, OLD.source_identity_digest,
                    OLD.payload_digest, OLD.source_fact_ref, OLD.original_issuer,
                    OLD.original_source_number, OLD.created_at) THEN
                    RAISE EXCEPTION 'billium: import mapping source identity, digest and historical document number are immutable' USING ERRCODE = '55000';
                END IF;
                IF OLD.billium_target_ref IS NOT NULL AND (
                    NEW.billium_target_kind IS DISTINCT FROM OLD.billium_target_kind
                    OR NEW.billium_target_ref IS DISTINCT FROM OLD.billium_target_ref
                ) THEN
                    RAISE EXCEPTION 'billium: import mapping target binding is immutable once assigned' USING ERRCODE = '55000';
                END IF;
                IF OLD.status IN ('tombstoned', 'superseded') THEN
                    RAISE EXCEPTION 'billium: % import mapping is final and immutable', OLD.status USING ERRCODE = '55000';
                END IF;
                IF OLD.status = 'quarantined' AND NEW.status NOT IN ('quarantined', 'tombstoned') THEN
                    RAISE EXCEPTION 'billium: quarantined import mapping can only move to tombstoned' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_import_mapping_guard() FROM PUBLIC;
            CREATE TRIGGER billium_import_mapping_guard BEFORE UPDATE OR DELETE ON public.billium_import_mapping
                FOR EACH ROW EXECUTE FUNCTION public.billium_import_mapping_guard();

            CREATE TRIGGER billium_import_receipt_append_only BEFORE UPDATE OR DELETE ON public.billium_import_receipt
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            SQL);

        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_import_batch');
        RuntimeGrants::grant(
            'UPDATE (status, approval_ref, authority_fence_ref, import_policy_version, validated_at, approved_at, completed_at, rejected_at)',
            'public.billium_import_batch',
        );
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_import_record');
        RuntimeGrants::grant(
            'UPDATE (validation_status, reason_codes, missing_evidence, rejection_code, source_fact_ref)',
            'public.billium_import_record',
        );
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_import_mapping');
        RuntimeGrants::grant(
            'UPDATE (billium_target_kind, billium_target_ref, import_policy_version, status, tombstone_reason, tombstoned_at, superseded_by_revision)',
            'public.billium_import_mapping',
        );
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_import_receipt');
    }

    public function down(): void
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
