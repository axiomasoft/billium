<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // D13/D19/D22/D23/D27/D56/D63: usage owns snapshots, evidence and its internal notifications.
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.billium_usage_fact ADD COLUMN ingest_seq bigint GENERATED ALWAYS AS IDENTITY;

            CREATE TABLE public.billium_usage_source_finality_contract (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                contract_ref text NOT NULL,
                version integer NOT NULL CHECK (version>=1),
                required_sources jsonb NOT NULL CHECK (jsonb_typeof(required_sources)='array' AND jsonb_array_length(required_sources)>=1),
                late_grace_micros bigint NOT NULL CHECK (late_grace_micros>=0),
                post_final_policy text NOT NULL CHECK (post_final_policy IN ('accept_adjust','reject','manual_review')),
                reopen_allowed boolean NOT NULL,
                contract_digest text NOT NULL,
                UNIQUE (seller_ref,environment,contract_ref,version),
                CHECK (NOT (reopen_allowed AND post_final_policy<>'accept_adjust'))
            );
            CREATE TABLE public.billium_usage_aggregation_policy (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL,
                metric_ref text NOT NULL,
                metric_version text NOT NULL,
                metric_definition_digest text NOT NULL,
                semantics text NOT NULL CHECK (semantics IN ('additive','gauge','distinct_exact')),
                algorithm_id text NOT NULL,
                algorithm_version integer NOT NULL CHECK (algorithm_version>=1),
                gauge_policy text NULL CHECK (gauge_policy IN ('latest_as_of','max','time_weighted')),
                missing_initial text NOT NULL CHECK (missing_initial IN ('refuse','declared_zero')),
                carry_forward boolean NOT NULL,
                max_carry_seconds integer NULL CHECK (max_carry_seconds>=0),
                time_weight_unit_seconds integer NULL CHECK (time_weight_unit_seconds>0),
                quantity_scale smallint NOT NULL CHECK (quantity_scale BETWEEN 0 AND 12),
                rounding_mode text NOT NULL CHECK (rounding_mode IN ('down','up','half_up','half_even')),
                allow_negative boolean NOT NULL,
                finality_contract_ref text NOT NULL,
                finality_contract_version integer NOT NULL,
                identity_key_version text NULL,
                identity_namespace text NULL,
                cross_source_identity_policy text NOT NULL CHECK (cross_source_identity_policy IN ('shared','source_scoped')),
                UNIQUE (seller_ref,environment,metric_ref,metric_version),
                FOREIGN KEY (seller_ref,environment,metric_ref,metric_version)
                    REFERENCES public.billium_usage_metric (seller_ref,environment,metric_ref,metric_version),
                FOREIGN KEY (seller_ref,environment,finality_contract_ref,finality_contract_version)
                    REFERENCES public.billium_usage_source_finality_contract (seller_ref,environment,contract_ref,version),
                CHECK ((semantics='gauge')=(gauge_policy IS NOT NULL)),
                CHECK ((algorithm_id='gauge_time_weighted')=(time_weight_unit_seconds IS NOT NULL)),
                CHECK ((semantics='additive' AND algorithm_id='additive_sum')
                    OR (semantics='distinct_exact' AND algorithm_id='distinct_exact_union')
                    OR (semantics='gauge' AND algorithm_id='gauge_' || gauge_policy)),
                CHECK (semantics<>'distinct_exact' OR (quantity_scale=0 AND NOT allow_negative
                    AND identity_key_version IS NOT NULL AND identity_key_version<>''
                    AND identity_namespace IS NOT NULL AND identity_namespace<>''))
            );
            CREATE TABLE public.billium_usage_source_head (
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                source_ref text COLLATE "C" NOT NULL,
                row_version bigint NOT NULL DEFAULT 1,
                PRIMARY KEY (seller_ref,environment,source_ref)
            );
            CREATE TABLE public.billium_usage_source_completeness (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL,
                source_ref text NOT NULL,
                evidence_seq bigint NOT NULL CHECK (evidence_seq>=1),
                status text NOT NULL CHECK (status IN ('complete','delayed','gap','dropped','retention_expired')),
                range_start_micros bigint NOT NULL,
                range_end_micros bigint NOT NULL CHECK (range_end_micros>range_start_micros),
                watermark_micros bigint NOT NULL,
                repair_or_replay_ref text NULL,
                evidence_digest text NOT NULL,
                UNIQUE (seller_ref,environment,source_ref,evidence_seq),
                FOREIGN KEY (seller_ref,environment,source_ref)
                    REFERENCES public.billium_usage_source_head (seller_ref,environment,source_ref),
                CHECK (status IN ('complete','delayed') OR (repair_or_replay_ref IS NOT NULL AND repair_or_replay_ref<>''))
            );
            CREATE TABLE public.billium_usage_aggregate_series_head (
                seller_ref text NOT NULL,
                environment text NOT NULL,
                metric_ref text NOT NULL,
                metric_version text NOT NULL,
                group_hash text NOT NULL,
                series_key text COLLATE "C" NOT NULL,
                window_start_micros bigint NOT NULL,
                window_end_micros bigint NOT NULL CHECK (window_end_micros>window_start_micros),
                head_revision integer NOT NULL DEFAULT 0 CHECK (head_revision>=0),
                head_digest text NULL,
                row_version bigint NOT NULL DEFAULT 1,
                PRIMARY KEY (seller_ref,environment,metric_ref,metric_version,group_hash,window_start_micros,window_end_micros),
                UNIQUE (seller_ref,environment,series_key),
                CHECK ((head_revision=0)=(head_digest IS NULL))
            );
            CREATE TABLE public.billium_usage_aggregate_snapshot (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                snapshot_ref text NOT NULL UNIQUE,
                snapshot_digest text NOT NULL UNIQUE,
                seller_ref text NOT NULL,
                environment text NOT NULL,
                metric_ref text NOT NULL,
                metric_version text NOT NULL,
                group_hash text NOT NULL,
                group_key jsonb NOT NULL CHECK (jsonb_typeof(group_key)='object'),
                series_key text NOT NULL,
                window_start_micros bigint NOT NULL,
                window_end_micros bigint NOT NULL CHECK (window_end_micros>window_start_micros),
                snapshot_revision integer NOT NULL CHECK (snapshot_revision>=1),
                predecessor_digest text NULL,
                algorithm_id text NOT NULL,
                algorithm_version integer NOT NULL CHECK (algorithm_version>=1),
                quantity numeric(38,12) NOT NULL,
                quantity_scale smallint NOT NULL CHECK (quantity_scale BETWEEN 0 AND 12),
                distinct_count bigint NULL CHECK (distinct_count>=0),
                member_set_digest text NULL,
                finality_contract_ref text NOT NULL,
                finality_contract_version integer NOT NULL,
                completeness_frontier jsonb NOT NULL,
                completeness_frontier_digest text NOT NULL,
                reduction_evidence jsonb NOT NULL CHECK (jsonb_typeof(reduction_evidence)='object'),
                ingest_frontier_seq bigint NOT NULL,
                reproducibility text NOT NULL DEFAULT 'reproducible' CHECK (reproducibility='reproducible'),
                UNIQUE (seller_ref,environment,series_key,snapshot_revision),
                UNIQUE (id,seller_ref,environment),
                FOREIGN KEY (seller_ref,environment,metric_ref,metric_version)
                    REFERENCES public.billium_usage_aggregation_policy (seller_ref,environment,metric_ref,metric_version),
                FOREIGN KEY (seller_ref,environment,finality_contract_ref,finality_contract_version)
                    REFERENCES public.billium_usage_source_finality_contract (seller_ref,environment,contract_ref,version),
                CHECK ((snapshot_revision=1)=(predecessor_digest IS NULL)),
                CHECK (quantity=trunc(quantity,quantity_scale)),
                CHECK ((algorithm_id='distinct_exact_union')=(distinct_count IS NOT NULL)),
                CHECK ((distinct_count IS NULL)=(member_set_digest IS NULL))
            );
            CREATE TABLE public.billium_usage_aggregate_member (
                snapshot_id bigint NOT NULL REFERENCES public.billium_usage_aggregate_snapshot(id),
                fact_id text NOT NULL,
                fact_revision bigint NOT NULL CHECK (fact_revision>=1),
                fact_digest text NOT NULL,
                source_ref text NOT NULL,
                source_sequence bigint NULL,
                observed_at_micros bigint NOT NULL,
                quantity_unscaled numeric(38,0) NOT NULL,
                PRIMARY KEY (snapshot_id,fact_id)
            );
            CREATE TABLE public.billium_usage_aggregate_distinct_member (
                snapshot_id bigint NOT NULL REFERENCES public.billium_usage_aggregate_snapshot(id),
                member_id char(64) NULL CHECK (member_id IS NULL OR member_id ~ '^[0-9a-f]{64}$'),
                identity_key_version text NOT NULL,
                identity_namespace text NOT NULL,
                contributing_fact_id text NOT NULL,
                member_slot bigint GENERATED ALWAYS AS IDENTITY,
                PRIMARY KEY (snapshot_id,member_slot),
                FOREIGN KEY (snapshot_id,contributing_fact_id)
                    REFERENCES public.billium_usage_aggregate_member (snapshot_id,fact_id)
            );
            CREATE UNIQUE INDEX billium_usage_distinct_contribution ON public.billium_usage_aggregate_distinct_member
                (snapshot_id,member_id,contributing_fact_id) WHERE member_id IS NOT NULL;
            CREATE TABLE public.billium_usage_aggregate_reproducibility_event (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                snapshot_id bigint NOT NULL REFERENCES public.billium_usage_aggregate_snapshot(id),
                reproducibility text NOT NULL CHECK (reproducibility IN ('retention_expired','erased'))
            );
            CREATE TABLE public.billium_usage_aggregate_event (
                snapshot_id bigint PRIMARY KEY REFERENCES public.billium_usage_aggregate_snapshot(id),
                seller_ref text NOT NULL,
                environment text NOT NULL,
                event_type text NOT NULL CHECK (event_type='usage.aggregate.frozen.v1'),
                payload jsonb NOT NULL CHECK (jsonb_typeof(payload)='object' AND octet_length(payload::text)<=65536)
            );

            CREATE TRIGGER billium_usage_finality_immutable BEFORE UPDATE OR DELETE ON public.billium_usage_source_finality_contract
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_usage_policy_immutable BEFORE UPDATE OR DELETE ON public.billium_usage_aggregation_policy
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_usage_completeness_immutable BEFORE UPDATE OR DELETE ON public.billium_usage_source_completeness
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_usage_snapshot_immutable BEFORE UPDATE OR DELETE ON public.billium_usage_aggregate_snapshot
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_usage_member_immutable BEFORE UPDATE OR DELETE ON public.billium_usage_aggregate_member
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_usage_reproducibility_immutable BEFORE UPDATE OR DELETE ON public.billium_usage_aggregate_reproducibility_event
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_usage_event_immutable BEFORE UPDATE OR DELETE ON public.billium_usage_aggregate_event
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE OR REPLACE FUNCTION public.billium_usage_guard_member_erasure() RETURNS trigger LANGUAGE plpgsql
            SET search_path=pg_catalog,pg_temp AS $$
            BEGIN
                IF TG_OP='UPDATE' AND current_user=pg_get_userbyid((SELECT relowner FROM pg_class WHERE oid=TG_RELID))
                    AND current_setting('billium.usage_erasure',true)='on' AND NEW.member_id IS NULL
                    AND (to_jsonb(NEW)-'member_id')=(to_jsonb(OLD)-'member_id') THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'immutable distinct membership' USING ERRCODE='55000';
            END $$;
            CREATE TRIGGER billium_usage_distinct_immutable BEFORE UPDATE OR DELETE ON public.billium_usage_aggregate_distinct_member
                FOR EACH ROW EXECUTE FUNCTION public.billium_usage_guard_member_erasure();

            CREATE OR REPLACE FUNCTION public.billium_usage_erase_distinct_members(p_seller text,p_environment text,p_snapshot_ids bigint[])
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,pg_temp AS $$
            DECLARE n integer;
            BEGIN
                IF cardinality(p_snapshot_ids)>1000 THEN
                    RAISE EXCEPTION 'bounded erasure scope required';
                END IF;
                PERFORM h.series_key FROM public.billium_usage_aggregate_series_head h
                    WHERE h.seller_ref=p_seller AND h.environment=p_environment
                    AND h.series_key IN (SELECT s.series_key FROM public.billium_usage_aggregate_snapshot s WHERE s.id=ANY(p_snapshot_ids)
                        AND s.seller_ref=p_seller AND s.environment=p_environment)
                    ORDER BY h.series_key COLLATE "C" FOR UPDATE;
                PERFORM set_config('billium.usage_erasure','on',true);
                UPDATE public.billium_usage_aggregate_distinct_member m SET member_id=NULL
                    FROM public.billium_usage_aggregate_snapshot s
                    WHERE m.snapshot_id=s.id AND s.id=ANY(p_snapshot_ids) AND s.seller_ref=p_seller AND s.environment=p_environment;
                GET DIAGNOSTICS n=ROW_COUNT;
                PERFORM set_config('billium.usage_erasure','off',true);
                INSERT INTO public.billium_usage_aggregate_reproducibility_event (snapshot_id,reproducibility)
                    SELECT s.id,'erased' FROM public.billium_usage_aggregate_snapshot s
                    WHERE s.id=ANY(p_snapshot_ids) AND s.seller_ref=p_seller AND s.environment=p_environment;
                RETURN n;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_usage_guard_member_erasure() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.billium_usage_erase_distinct_members(text,text,bigint[]) FROM PUBLIC;
            SQL);
        $runtime = config('billium.connection') ?? config('database.default');
        $username = DB::connection($runtime)->getConfig('username');
        if (! is_string($username) || $username === '') {
            throw new LogicException('Usage runtime role is required');
        }
        $role = '"'.str_replace('"', '""', $username).'"';
        foreach ([
            'source_finality_contract', 'aggregation_policy', 'source_head', 'source_completeness',
            'aggregate_series_head', 'aggregate_snapshot', 'aggregate_member', 'aggregate_distinct_member',
            'aggregate_reproducibility_event', 'aggregate_event',
        ] as $name) {
            DB::unprepared('REVOKE ALL ON TABLE public.billium_usage_'.$name.' FROM PUBLIC, '.$role);
            DB::unprepared('GRANT SELECT, INSERT ON TABLE public.billium_usage_'.$name.' TO '.$role);
        }
        DB::unprepared('GRANT UPDATE (head_revision,head_digest,row_version) ON TABLE public.billium_usage_aggregate_series_head TO '.$role);
        DB::unprepared('GRANT UPDATE (row_version) ON TABLE public.billium_usage_source_head TO '.$role);
        DB::unprepared('REVOKE INSERT ON TABLE public.billium_usage_aggregate_reproducibility_event FROM '.$role);
        DB::unprepared('REVOKE ALL ON FUNCTION public.billium_usage_erase_distinct_members(text,text,bigint[]) FROM '.$role);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
