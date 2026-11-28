<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

/**
 * Document rendering: render requests and render blobs.
 */
return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_document_render_request (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                render_request_ref text NOT NULL,
                legal_document_id bigint NOT NULL REFERENCES public.billium_document_legal(id),
                legal_document_ref text NOT NULL,
                document_revision integer NOT NULL CHECK (document_revision >= 1),
                approved_snapshot_digest text NOT NULL,
                template_id text NOT NULL,
                template_version text NOT NULL,
                template_digest text NOT NULL,
                locale text NOT NULL,
                renderer_name text NOT NULL,
                renderer_version text NOT NULL,
                renderer_digest text NOT NULL,
                render_key_digest text NOT NULL,
                render_revision integer NOT NULL CHECK (render_revision >= 1),
                attempt integer NOT NULL CHECK (attempt >= 0),
                state text NOT NULL CHECK (state IN ('pending','rendering','ready','failed','superseded')),
                blob_ref text NULL,
                output_digest text NULL,
                content_type text NULL,
                byte_size bigint NULL CHECK (byte_size IS NULL OR byte_size >= 0),
                redacted_diagnostics jsonb NOT NULL CHECK (jsonb_typeof(redacted_diagnostics) = 'object'),
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                UNIQUE (seller_ref, environment, render_request_ref),
                UNIQUE (seller_ref, environment, render_key_digest),
                UNIQUE (seller_ref, environment, legal_document_id, template_id, locale, render_revision),
                CHECK (
                    state <> 'ready'
                    OR (
                        blob_ref IS NOT NULL
                        AND output_digest IS NOT NULL
                        AND content_type IS NOT NULL
                        AND byte_size IS NOT NULL
                    )
                )
            );

            CREATE TABLE public.billium_document_render_blob (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                upload_key text NOT NULL,
                blob_ref text NOT NULL,
                render_request_id bigint NULL REFERENCES public.billium_document_render_request(id),
                output_digest text NOT NULL,
                content_type text NOT NULL,
                byte_size bigint NOT NULL CHECK (byte_size >= 0),
                status text NOT NULL,
                retention_pinned_until timestamptz NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                UNIQUE (seller_ref, environment, upload_key),
                UNIQUE (seller_ref, environment, blob_ref),
                CONSTRAINT billium_document_render_blob_status_check
                    CHECK (status IN ('uploaded', 'attached', 'orphaned', 'deleting', 'deleted'))
            );

            CREATE OR REPLACE FUNCTION public.billium_documents_render_request_guard()
            RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium_document_render_request cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF (NEW.seller_ref, NEW.environment, NEW.render_request_ref, NEW.legal_document_id, NEW.legal_document_ref,
                    NEW.document_revision, NEW.approved_snapshot_digest, NEW.template_id, NEW.template_version, NEW.template_digest,
                    NEW.locale, NEW.renderer_name, NEW.renderer_version, NEW.renderer_digest, NEW.render_key_digest, NEW.render_revision)
                   IS DISTINCT FROM
                   (OLD.seller_ref, OLD.environment, OLD.render_request_ref, OLD.legal_document_id, OLD.legal_document_ref,
                    OLD.document_revision, OLD.approved_snapshot_digest, OLD.template_id, OLD.template_version, OLD.template_digest,
                    OLD.locale, OLD.renderer_name, OLD.renderer_version, OLD.renderer_digest, OLD.render_key_digest, OLD.render_revision) THEN
                    RAISE EXCEPTION 'billium_document_render_request identity and key digests are immutable' USING ERRCODE = '23514';
                END IF;
                IF OLD.state = 'superseded' THEN
                    RAISE EXCEPTION 'superseded render_request cannot mutate' USING ERRCODE = '23514';
                END IF;
                IF OLD.state = 'ready' AND (NEW.state, NEW.blob_ref, NEW.output_digest, NEW.content_type, NEW.byte_size)
                   IS DISTINCT FROM (OLD.state, OLD.blob_ref, OLD.output_digest, OLD.content_type, OLD.byte_size) THEN
                    RAISE EXCEPTION 'ready render output is immutable' USING ERRCODE = '23514';
                END IF;
                IF NEW.attempt < OLD.attempt THEN
                    RAISE EXCEPTION 'render attempt counter cannot decrease' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_documents_render_request_guard() FROM PUBLIC;

            CREATE TRIGGER billium_document_render_request_guard_trg
                BEFORE UPDATE OR DELETE ON public.billium_document_render_request
                FOR EACH ROW EXECUTE FUNCTION public.billium_documents_render_request_guard();

            CREATE OR REPLACE FUNCTION public.billium_documents_render_blob_guard()
            RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium_document_render_blob cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF (NEW.seller_ref, NEW.environment, NEW.upload_key, NEW.blob_ref, NEW.output_digest, NEW.content_type, NEW.byte_size)
                   IS DISTINCT FROM
                   (OLD.seller_ref, OLD.environment, OLD.upload_key, OLD.blob_ref, OLD.output_digest, OLD.content_type, OLD.byte_size) THEN
                    RAISE EXCEPTION 'render blob metadata is immutable' USING ERRCODE = '23514';
                END IF;
                IF OLD.status = 'attached' AND NEW.status IN ('orphaned', 'deleting', 'deleted') THEN
                    RAISE EXCEPTION 'attached render blob cannot be orphaned or deleted' USING ERRCODE = '23514';
                END IF;
                IF OLD.status = 'deleting' AND NEW.status NOT IN ('deleting', 'deleted') THEN
                    RAISE EXCEPTION 'deleting render blob can only transition to deleted' USING ERRCODE = '23514';
                END IF;
                IF OLD.status = 'deleted' AND NEW.status <> 'deleted' THEN
                    RAISE EXCEPTION 'deleted render blob status is terminal' USING ERRCODE = '23514';
                END IF;
                IF OLD.retention_pinned_until IS NOT NULL AND (NEW.retention_pinned_until IS NULL OR NEW.retention_pinned_until < OLD.retention_pinned_until) THEN
                    RAISE EXCEPTION 'retention pin cannot be shortened or removed' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_documents_render_blob_guard() FROM PUBLIC;

            CREATE TRIGGER billium_document_render_blob_guard_trg
                BEFORE UPDATE OR DELETE ON public.billium_document_render_blob
                FOR EACH ROW EXECUTE FUNCTION public.billium_documents_render_blob_guard();

            -- cleanupOrphanBlobs reads the seller's orphaned and half-deleted blobs in id order. Attached and deleted blobs
            -- are retained, so without this partial index every sweep walked all render blobs of the seller.
            CREATE INDEX billium_document_render_blob_cleanup_idx
                ON public.billium_document_render_blob (seller_ref, environment, id)
                WHERE status IN ('orphaned', 'deleting');
            SQL);

        RuntimeGrants::grant('SELECT, INSERT, UPDATE', 'public.billium_document_render_request');
        RuntimeGrants::grant('SELECT, INSERT, UPDATE', 'public.billium_document_render_blob');
    }

    public function down(): never
    {
        throw new LogicException('Billium migrations are forward-only.');
    }
};
