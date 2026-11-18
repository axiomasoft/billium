<?php

declare(strict_types=1);

use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.billium_feed_partition_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium: feed partitions are never deleted' USING ERRCODE = '55000';
                END IF;
                IF TG_OP = 'INSERT' THEN
                    IF EXISTS (SELECT 1 FROM public.billium_feed_partition p
                        WHERE (p.channel,p.source_authority,p.seller_ref,p.environment,p.partition_ref)=
                              (NEW.channel,NEW.source_authority,NEW.seller_ref,NEW.environment,NEW.partition_ref) AND p.state='retired')
                       AND NOT EXISTS (SELECT 1 FROM public.billium_feed_bootstrap_session b
                           JOIN public.billium_feed_partition p ON p.id=b.old_partition_id
                           WHERE (b.channel,b.source_authority,b.seller_ref,b.environment,b.partition_ref,b.source_lineage)=
                                 (NEW.channel,NEW.source_authority,NEW.seller_ref,NEW.environment,NEW.partition_ref,NEW.source_lineage)
                             AND b.status='ready' AND p.state='retired' AND NEW.generation=p.generation+1
                             AND NEW.custody_sequence=b.cursor_sequence AND NEW.accounted_sequence=b.cursor_sequence
                             AND NOT EXISTS (SELECT 1 FROM public.billium_feed_bootstrap_item i WHERE i.session_id=b.id AND i.state='staged')
                             AND NOT EXISTS (SELECT 1 FROM public.billium_feed_bootstrap_session used WHERE used.old_partition_id=p.id AND used.status='adopted'))
                    THEN RAISE EXCEPTION 'billium: a retired namespace requires ready bootstrap adoption' USING ERRCODE='55000'; END IF;
                    RETURN NEW;
                END IF;
                IF NEW.state='retired' AND OLD.state <> 'retired' AND NOT EXISTS (
                    SELECT 1 FROM public.billium_feed_bootstrap_session b
                    WHERE b.old_partition_id=OLD.id AND b.status='ready'
                      AND (b.channel,b.source_authority,b.seller_ref,b.environment)=
                          (OLD.channel,OLD.source_authority,OLD.seller_ref,OLD.environment)
                      AND ((b.partition_ref=OLD.partition_ref AND NOT EXISTS (
                              SELECT FROM public.billium_feed_observation_scope_link WHERE partition_id=OLD.id OR bootstrap_session_id=b.id))
                          OR EXISTS (SELECT FROM public.billium_feed_observation_scope_link prior
                              JOIN public.billium_feed_observation_scope_link manifest ON manifest.scope_id=prior.scope_id
                              WHERE prior.partition_id=OLD.id AND manifest.bootstrap_session_id=b.id))
                      AND NOT EXISTS (SELECT 1 FROM public.billium_feed_bootstrap_item i WHERE i.session_id=b.id AND i.state='staged'))
                THEN RAISE EXCEPTION 'billium: retirement requires ready bootstrap adoption' USING ERRCODE='55000'; END IF;
                IF (NEW.id, NEW.channel, NEW.source_authority, NEW.source_lineage, NEW.seller_ref, NEW.environment, NEW.partition_ref, NEW.generation, NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.id, OLD.channel, OLD.source_authority, OLD.source_lineage, OLD.seller_ref, OLD.environment, OLD.partition_ref, OLD.generation, OLD.created_at) THEN
                    RAISE EXCEPTION 'billium: feed partition source identity is immutable' USING ERRCODE = '55000';
                END IF;
                IF NEW.custody_sequence < OLD.custody_sequence OR NEW.accounted_sequence < OLD.accounted_sequence THEN
                    RAISE EXCEPTION 'billium: partition frontiers only advance (recovery adopts a new partition)' USING ERRCODE = '55000';
                END IF;
                IF OLD.state = 'retired' AND NEW.state <> 'retired' THEN
                    RAISE EXCEPTION 'billium: a retired partition stays retired' USING ERRCODE = '55000';
                END IF;
                IF OLD.state = 'recovery_required' AND NEW.state = 'active' THEN
                    RAISE EXCEPTION 'billium: recovery is left by adopting a verified bootstrap, never by reactivation' USING ERRCODE = '55000';
                END IF;
                IF OLD.state <> 'active' AND (NEW.accounted_sequence <> OLD.accounted_sequence OR NEW.custody_sequence <> OLD.custody_sequence) THEN
                    RAISE EXCEPTION 'billium: a partition outside active state does not advance' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END $$;

            CREATE FUNCTION public.billium_qualified_retirement_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            BEGIN
                IF EXISTS (SELECT FROM public.billium_feed_observation_scope_link WHERE partition_id=OLD.id)
                    AND NOT EXISTS (
                        SELECT FROM public.billium_feed_bootstrap_session b
                        JOIN public.billium_feed_partition successor ON successor.id=b.new_partition_id
                        JOIN public.billium_feed_observation_scope_link prior ON prior.partition_id=OLD.id
                        JOIN public.billium_feed_observation_scope_link manifest ON manifest.bootstrap_session_id=b.id AND manifest.scope_id=prior.scope_id
                        JOIN public.billium_feed_observation_scope_link replacement ON replacement.partition_id=successor.id AND replacement.scope_id=prior.scope_id
                        WHERE b.old_partition_id=OLD.id AND b.status='adopted' AND successor.generation=OLD.generation+1
                          AND (b.channel,b.source_authority,b.seller_ref,b.environment,b.partition_ref,b.source_lineage)=
                              (successor.channel,successor.source_authority,successor.seller_ref,successor.environment,successor.partition_ref,successor.source_lineage)
                          AND b.cursor_sequence=successor.accounted_sequence AND successor.custody_sequence>=b.cursor_sequence
                          AND NOT EXISTS (SELECT FROM public.billium_feed_bootstrap_item i WHERE i.session_id=b.id AND i.state='staged'))
                THEN RAISE EXCEPTION 'billium: qualified retirement requires committed adoption of the same root' USING ERRCODE='55000'; END IF;
                RETURN NULL;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_qualified_retirement_guard() FROM PUBLIC;
            CREATE CONSTRAINT TRIGGER billium_qualified_retirement_guard AFTER UPDATE ON public.billium_feed_partition
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (OLD.state <> 'retired' AND NEW.state='retired')
                EXECUTE FUNCTION public.billium_qualified_retirement_guard();
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
