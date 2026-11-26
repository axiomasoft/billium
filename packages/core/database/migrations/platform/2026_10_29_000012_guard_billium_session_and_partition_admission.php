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
            CREATE OR REPLACE FUNCTION public.billium_financial_session_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.state <> 'recovery' OR NEW.activation_certificate_ref IS NOT NULL OR NEW.sealed_at IS NOT NULL
                       OR NEW.epoch <> coalesce((SELECT max(s.epoch) FROM public.billium_financial_session s WHERE s.installation_ref=NEW.installation_ref),0)+1
                    THEN RAISE EXCEPTION 'billium: a new epoch opens in recovery and requires activation' USING ERRCODE='55000'; END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium: financial sessions are never deleted' USING ERRCODE = '55000';
                END IF;
                IF (NEW.id, NEW.installation_ref, NEW.epoch, NEW.external_owner_session_ref, NEW.deployment_generation,
                    NEW.external_attachment_generation, NEW.authority_epoch, NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.id, OLD.installation_ref, OLD.epoch, OLD.external_owner_session_ref, OLD.deployment_generation,
                    OLD.external_attachment_generation, OLD.authority_epoch, OLD.created_at)
                   OR (OLD.activation_certificate_ref IS NOT NULL AND NEW.activation_certificate_ref IS DISTINCT FROM OLD.activation_certificate_ref)
                   OR (OLD.sealed_at IS NOT NULL AND NEW.sealed_at IS DISTINCT FROM OLD.sealed_at) THEN
                    RAISE EXCEPTION 'billium: financial session identity is immutable' USING ERRCODE = '55000';
                END IF;
                IF NEW.state IS DISTINCT FROM OLD.state AND NOT (
                       (OLD.state = 'recovery' AND NEW.state = 'active')
                    OR (OLD.state = 'active' AND NEW.state IN ('sealing', 'sealed', 'recovery'))
                    OR (OLD.state = 'sealing' AND NEW.state IN ('sealed', 'recovery'))
                ) THEN
                    RAISE EXCEPTION 'billium: financial session % -> % is not a transition', OLD.state, NEW.state USING ERRCODE = '55000';
                END IF;
                IF NEW.state = 'active' AND OLD.state = 'recovery' AND EXISTS (
                    SELECT 1 FROM public.billium_financial_session s
                    WHERE s.installation_ref = NEW.installation_ref AND s.epoch > NEW.epoch
                ) THEN
                    RAISE EXCEPTION 'billium: only the latest epoch may be activated (D54)' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END $$;

            DROP TRIGGER billium_financial_session_guard ON public.billium_financial_session;
            CREATE TRIGGER billium_financial_session_guard BEFORE INSERT OR UPDATE OR DELETE ON public.billium_financial_session
                FOR EACH ROW EXECUTE FUNCTION public.billium_financial_session_guard();

            -- Runtime has no direct session DML. Each capability performs the row change and its event together.
            CREATE OR REPLACE FUNCTION public.billium_open_financial_session(
                p_installation text,p_owner text,p_deployment bigint,p_attachment bigint,p_authority bigint,
                p_transition text,p_evidence text,p_actor text)
            RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE s public.billium_financial_session; v_id bigint;
            BEGIN
                IF coalesce(p_transition,'')='' OR coalesce(p_evidence,'')='' OR coalesce(p_actor,'')=''
                THEN RAISE EXCEPTION 'billium: session opening needs transition evidence' USING ERRCODE='23514'; END IF;
                PERFORM 1 FROM public.billium_installation_state WHERE installation_ref=p_installation FOR UPDATE;
                IF NOT FOUND THEN RAISE EXCEPTION 'billium: unknown installation' USING ERRCODE='23503'; END IF;
                SELECT * INTO s FROM public.billium_financial_session WHERE installation_ref=p_installation ORDER BY epoch DESC LIMIT 1 FOR UPDATE;
                IF s.state IN ('active','sealing') THEN
                    UPDATE public.billium_financial_session SET state='recovery',updated_at=now() WHERE id=s.id;
                    INSERT INTO public.billium_financial_session_event(session_id,transition_ref,transition,evidence_ref,actor_ref)
                    VALUES(s.id,p_transition,'superseded',p_evidence,p_actor);
                END IF;
                INSERT INTO public.billium_financial_session(installation_ref,epoch,external_owner_session_ref,deployment_generation,
                    external_attachment_generation,authority_epoch,state)
                VALUES(p_installation,coalesce(s.epoch,0)+1,p_owner,p_deployment,p_attachment,p_authority,'recovery') RETURNING id INTO v_id;
                INSERT INTO public.billium_financial_session_event(session_id,transition_ref,transition,evidence_ref,actor_ref)
                VALUES(v_id,p_transition,'opened',p_evidence,p_actor);
            END $$;

            CREATE OR REPLACE FUNCTION public.billium_transition_financial_session(
                p_installation text,p_epoch bigint,p_state text,p_certificate text,p_transition text,p_admission text,p_evidence text,p_actor text)
            RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE s public.billium_financial_session; v_transition text;
            BEGIN
                IF coalesce(p_transition,'')='' OR coalesce(p_actor,'')=''
                THEN RAISE EXCEPTION 'billium: session transition needs an identity' USING ERRCODE='23514'; END IF;
                SELECT * INTO s FROM public.billium_financial_session WHERE installation_ref=p_installation ORDER BY epoch DESC LIMIT 1 FOR UPDATE;
                IF s.id IS NULL OR s.epoch IS DISTINCT FROM p_epoch
                THEN RAISE EXCEPTION 'billium: only the current epoch may transition' USING ERRCODE='55000'; END IF;
                IF p_state='active' AND s.state='recovery' AND coalesce(p_certificate,'')<>'' AND coalesce(p_admission,'')<>'' AND coalesce(p_evidence,'')<>'' THEN
                    v_transition := 'activated';
                ELSIF p_state='sealing' AND s.state='active' THEN
                    v_transition := 'sealing';
                ELSIF p_state='sealed' AND s.state IN ('active','sealing') THEN
                    v_transition := 'sealed';
                ELSE
                    RAISE EXCEPTION 'billium: invalid session transition or missing activation evidence' USING ERRCODE='55000';
                END IF;
                UPDATE public.billium_financial_session SET state=p_state,updated_at=now(),
                    activation_certificate_ref=CASE WHEN p_state='active' THEN p_certificate ELSE activation_certificate_ref END,
                    sealed_at=CASE WHEN p_state='sealed' THEN now() ELSE sealed_at END WHERE id=s.id;
                INSERT INTO public.billium_financial_session_event(session_id,transition_ref,transition,external_admission_ref,evidence_ref,actor_ref)
                VALUES(s.id,p_transition,v_transition,p_admission,p_evidence,p_actor);
            END $$;
            REVOKE ALL ON FUNCTION public.billium_open_financial_session(text,text,bigint,bigint,bigint,text,text,text) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.billium_transition_financial_session(text,bigint,text,text,text,text,text,text) FROM PUBLIC;

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
                      AND (b.channel,b.source_authority,b.seller_ref,b.environment,b.partition_ref)=
                          (OLD.channel,OLD.source_authority,OLD.seller_ref,OLD.environment,OLD.partition_ref)
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

            DROP TRIGGER billium_feed_partition_guard ON public.billium_feed_partition;
            CREATE TRIGGER billium_feed_partition_guard BEFORE INSERT OR UPDATE OR DELETE ON public.billium_feed_partition
                FOR EACH ROW EXECUTE FUNCTION public.billium_feed_partition_guard();

            -- Adoption inserts its successor before marking the session adopted. At commit both must agree;
            -- ready evidence alone never authorizes a replacement partition to become live.
            CREATE OR REPLACE FUNCTION public.billium_feed_partition_adoption_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM public.billium_feed_partition p
                    WHERE (p.channel,p.source_authority,p.seller_ref,p.environment,p.partition_ref)=
                          (NEW.channel,NEW.source_authority,NEW.seller_ref,NEW.environment,NEW.partition_ref) AND p.state='retired')
                   AND NOT EXISTS (SELECT 1 FROM public.billium_feed_bootstrap_session b
                       JOIN public.billium_feed_partition p ON p.id=b.old_partition_id
                       WHERE b.status='adopted' AND b.new_partition_id=NEW.id AND NEW.generation=p.generation+1
                         AND (b.channel,b.source_authority,b.seller_ref,b.environment,b.partition_ref,b.source_lineage)=
                             (NEW.channel,NEW.source_authority,NEW.seller_ref,NEW.environment,NEW.partition_ref,NEW.source_lineage)
                         AND NEW.custody_sequence=b.cursor_sequence AND NEW.accounted_sequence=b.cursor_sequence
                         AND NOT EXISTS (SELECT 1 FROM public.billium_feed_bootstrap_item i WHERE i.session_id=b.id AND i.state='staged'))
                THEN RAISE EXCEPTION 'billium: a successor must commit with its bootstrap adoption' USING ERRCODE='55000'; END IF;
                RETURN NULL;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_feed_partition_adoption_guard() FROM PUBLIC;
            DROP TRIGGER IF EXISTS billium_feed_partition_adoption_guard ON public.billium_feed_partition;
            CREATE CONSTRAINT TRIGGER billium_feed_partition_adoption_guard AFTER INSERT ON public.billium_feed_partition
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.billium_feed_partition_adoption_guard();
            SQL);
        $role = RuntimeGrants::role();
        if ($role !== null) {
            $quoted = '"'.str_replace('"', '""', $role).'"';
            DB::unprepared('REVOKE INSERT, UPDATE, DELETE ON public.billium_financial_session FROM PUBLIC, '.$quoted);
            DB::unprepared('REVOKE UPDATE (state, activation_certificate_ref, sealed_at, updated_at) ON public.billium_financial_session FROM PUBLIC, '.$quoted);
            DB::unprepared('REVOKE INSERT ON public.billium_financial_session_event FROM PUBLIC, '.$quoted);
        }
        RuntimeGrants::grant('EXECUTE', 'FUNCTION public.billium_open_financial_session(text,text,bigint,bigint,bigint,text,text,text)');
        RuntimeGrants::grant('EXECUTE', 'FUNCTION public.billium_transition_financial_session(text,bigint,text,text,text,text,text,text)');
        // PostgreSQL row locks require UPDATE on at least one column; this timestamp grants no state transition.
        RuntimeGrants::grant('UPDATE (updated_at)', 'public.billium_financial_session');
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
