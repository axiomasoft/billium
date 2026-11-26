<?php

declare(strict_types=1);

use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.billium_feed_bootstrap_item_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            DECLARE s public.billium_feed_bootstrap_session;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium: bootstrap snapshot items are never deleted' USING ERRCODE = '55000';
                END IF;
                IF TG_OP = 'INSERT' THEN
                    SELECT * INTO s FROM public.billium_feed_bootstrap_session WHERE id = NEW.session_id FOR SHARE;
                    IF NOT FOUND OR s.status <> 'loading'
                       OR (NEW.payload->>'sourceAuthority',NEW.payload->>'sourceLineage',NEW.payload->>'sellerRef',NEW.payload->>'environment',NEW.payload->>'partitionRef',
                           NEW.payload->>'operationRef',NEW.payload->>'operationRevision')
                          IS DISTINCT FROM (s.source_authority,s.source_lineage,s.seller_ref,s.environment,s.partition_ref,NEW.operation_ref,NEW.revision::text)
                    THEN RAISE EXCEPTION 'billium: bootstrap item differs from its manifest namespace' USING ERRCODE = '23514'; END IF;
                    RETURN NEW;
                END IF;
                IF (NEW.id, NEW.session_id, NEW.operation_ref, NEW.revision, NEW.material_digest, NEW.payload)
                   IS DISTINCT FROM (OLD.id, OLD.session_id, OLD.operation_ref, OLD.revision, OLD.material_digest, OLD.payload) THEN
                    RAISE EXCEPTION 'billium: snapshot content is immutable' USING ERRCODE = '55000';
                END IF;
                IF OLD.state <> 'staged' THEN
                    RAISE EXCEPTION 'billium: an item disposition is durable' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_feed_bootstrap_item_guard() FROM PUBLIC;
            DROP TRIGGER billium_feed_bootstrap_item_guard ON public.billium_feed_bootstrap_item;
            CREATE TRIGGER billium_feed_bootstrap_item_guard BEFORE INSERT OR UPDATE OR DELETE ON public.billium_feed_bootstrap_item
                FOR EACH ROW EXECUTE FUNCTION public.billium_feed_bootstrap_item_guard();

            -- A previously staged snapshot also has to meet the namespace/count invariant before it can advance.
            CREATE OR REPLACE FUNCTION public.billium_feed_bootstrap_manifest_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF NEW.status IN ('verified','applying','ready','adopted') AND (
                    (SELECT count(*) FROM public.billium_feed_bootstrap_item WHERE session_id=NEW.id) <> NEW.item_count
                    OR EXISTS (SELECT 1 FROM public.billium_feed_bootstrap_item i WHERE i.session_id=NEW.id
                        AND (i.payload->>'sourceAuthority',i.payload->>'sourceLineage',i.payload->>'sellerRef',i.payload->>'environment',i.payload->>'partitionRef',
                             i.payload->>'operationRef',i.payload->>'operationRevision')
                            IS DISTINCT FROM (NEW.source_authority,NEW.source_lineage,NEW.seller_ref,NEW.environment,NEW.partition_ref,i.operation_ref,i.revision::text)))
                THEN RAISE EXCEPTION 'billium: bootstrap manifest is incomplete or crosses namespaces' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_feed_bootstrap_manifest_guard() FROM PUBLIC;
            CREATE TRIGGER billium_feed_bootstrap_manifest_guard BEFORE UPDATE OF status ON public.billium_feed_bootstrap_session
                FOR EACH ROW EXECUTE FUNCTION public.billium_feed_bootstrap_manifest_guard();
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
