<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.billium_document_render_blob
                DROP CONSTRAINT IF EXISTS billium_document_render_blob_status_check,
                ADD CONSTRAINT billium_document_render_blob_status_check
                    CHECK (status IN ('uploaded', 'attached', 'orphaned', 'deleting', 'deleted')) NOT VALID;

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
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
