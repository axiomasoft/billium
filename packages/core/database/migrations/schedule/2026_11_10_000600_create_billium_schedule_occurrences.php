<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS btree_gist;
            CREATE TABLE public.billium_schedule_billing_schedule (
             id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
             seller_ref text NOT NULL, environment text NOT NULL CHECK (environment IN ('test','live')),
             schedule_ref text NOT NULL CHECK (length(schedule_ref) BETWEEN 1 AND 200),
             component_ref text NOT NULL, purpose text NOT NULL CHECK (purpose IN ('recurring_charge','usage_settlement')),
             current_revision integer NOT NULL CHECK (current_revision>=1),
             state text NOT NULL CHECK (state IN ('active','paused','cancelled','completed')),
             cancel_generation integer NOT NULL DEFAULT 0 CHECK (cancel_generation>=0),
             cursor_ordinal integer NOT NULL DEFAULT 0 CHECK (cursor_ordinal BETWEEN 0 AND 100000),
             next_occurrence_at timestamptz NULL, runnable boolean NOT NULL,
             claim_token uuid NULL, claim_expires_at timestamptz NULL,
             attempts integer NOT NULL DEFAULT 0 CHECK (attempts>=0), retry_at timestamptz NULL,
             row_version bigint NOT NULL DEFAULT 1 CHECK (row_version>=1),
             UNIQUE(seller_ref,environment,schedule_ref), UNIQUE(id,seller_ref,environment,component_ref),
             CHECK (runnable = (state='active' AND next_occurrence_at IS NOT NULL)),
             CHECK ((claim_token IS NULL) = (claim_expires_at IS NULL))
            );
            CREATE INDEX billium_schedule_due_idx ON public.billium_schedule_billing_schedule(seller_ref,state,next_occurrence_at,id) WHERE runnable;
            CREATE TABLE public.billium_schedule_billing_schedule_revision (
             schedule_id bigint NOT NULL REFERENCES public.billium_schedule_billing_schedule(id),
             revision integer NOT NULL CHECK (revision>=1),
             terms_digest text NOT NULL, inputs_digest text NOT NULL,
             terms jsonb NOT NULL CHECK (jsonb_typeof(terms)='object' AND octet_length(terms::text)<=32768),
             effective_from timestamptz NOT NULL,
             PRIMARY KEY(schedule_id,revision)
            );
            CREATE TRIGGER billium_schedule_billing_revision_immutable BEFORE UPDATE OR DELETE ON public.billium_schedule_billing_schedule_revision
             FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TABLE public.billium_schedule_billing_occurrence (
             id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
             seller_ref text NOT NULL, environment text NOT NULL,
             occurrence_ref text NOT NULL CHECK (occurrence_ref ~ '^occ_[0-9a-f]{48}$'),
             incarnation integer NOT NULL DEFAULT 1 CHECK (incarnation>=1),
             component_ref text NOT NULL, purpose text NOT NULL CHECK (purpose IN ('recurring_charge','usage_settlement','proration')),
             period_start timestamptz NOT NULL, period_end timestamptz NOT NULL CHECK (period_end>period_start),
             service_period tstzrange GENERATED ALWAYS AS (tstzrange(period_start,period_end,'[)')) STORED,
             coverage_class text GENERATED ALWAYS AS (CASE WHEN purpose='usage_settlement' THEN 'usage' ELSE 'time' END) STORED,
             schedule_id bigint NOT NULL, created_revision integer NOT NULL,
             ordinal integer NOT NULL DEFAULT 0 CHECK (ordinal BETWEEN 0 AND 100000),
             inputs_digest text NOT NULL,
             boundary_evidence jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(boundary_evidence)='object' AND octet_length(boundary_evidence::text)<=32768),
             state text NOT NULL CHECK (state IN ('planned','rated','obligated','superseded','cancelled')),
             parent_occurrence_ref text NULL, superseded_by_occurrence_ref text NULL,
             rated_basis_ref text NULL, cancel_generation integer NOT NULL CHECK (cancel_generation>=0),
             row_version bigint NOT NULL DEFAULT 1 CHECK (row_version>=1),
             UNIQUE(seller_ref,environment,occurrence_ref,incarnation),
             FOREIGN KEY(schedule_id,seller_ref,environment,component_ref)
              REFERENCES public.billium_schedule_billing_schedule(id,seller_ref,environment,component_ref),
             FOREIGN KEY(schedule_id,created_revision)
              REFERENCES public.billium_schedule_billing_schedule_revision(schedule_id,revision) DEFERRABLE INITIALLY DEFERRED,
             CHECK ((purpose='proration')=(parent_occurrence_ref IS NOT NULL)),
             CHECK ((state='superseded')=(superseded_by_occurrence_ref IS NOT NULL)),
             CHECK (state NOT IN ('rated','obligated') OR rated_basis_ref IS NOT NULL),
             CONSTRAINT billium_schedule_occurrence_no_overlap EXCLUDE USING gist (
              seller_ref WITH =, environment WITH =, component_ref WITH =, coverage_class WITH =, service_period WITH &&
             ) WHERE (state IN ('planned','rated','obligated'))
            );
            CREATE UNIQUE INDEX billium_schedule_occurrence_active_ref_uq
             ON public.billium_schedule_billing_occurrence(seller_ref,environment,occurrence_ref)
             WHERE state IN ('planned','rated','obligated');
            CREATE INDEX billium_schedule_occurrence_schedule_idx ON public.billium_schedule_billing_occurrence(schedule_id,period_start);
            CREATE OR REPLACE FUNCTION public.billium_schedule_occurrence_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE p public.billium_schedule_billing_occurrence%ROWTYPE; h public.billium_schedule_billing_schedule%ROWTYPE;
            BEGIN
             IF TG_OP='DELETE' THEN
              RAISE EXCEPTION 'billium.occurrence.transition_forbidden: DELETE' USING ERRCODE='55000';
             END IF;
             IF TG_OP='INSERT' THEN
              SELECT * INTO h FROM public.billium_schedule_billing_schedule WHERE id=NEW.schedule_id;
              IF NOT FOUND OR h.state<>'active' OR h.cancel_generation<>NEW.cancel_generation OR h.current_revision<>NEW.created_revision
                OR (NEW.purpose<>'proration' AND NEW.purpose<>h.purpose) THEN
               RAISE EXCEPTION 'billium.schedule.not_runnable' USING ERRCODE='23514';
              END IF;
              IF NEW.state<>'planned' THEN
               RAISE EXCEPTION 'billium.occurrence.transition_forbidden: initial state' USING ERRCODE='23514';
              END IF;
              IF NEW.purpose='proration' THEN
               SELECT * INTO p FROM public.billium_schedule_billing_occurrence
                WHERE seller_ref=NEW.seller_ref AND environment=NEW.environment AND occurrence_ref=NEW.parent_occurrence_ref
                ORDER BY incarnation DESC LIMIT 1;
               IF NOT FOUND OR p.component_ref<>NEW.component_ref OR p.purpose<>'recurring_charge'
                 OR NEW.period_start<p.period_start OR NEW.period_end>p.period_end THEN
                RAISE EXCEPTION 'billium.occurrence.parent_invalid' USING ERRCODE='23514';
               END IF;
              END IF;
              RETURN NEW;
             END IF;
             IF (NEW.seller_ref,NEW.environment,NEW.occurrence_ref,NEW.incarnation,NEW.component_ref,NEW.purpose,
              NEW.period_start,NEW.period_end,NEW.inputs_digest,NEW.schedule_id,NEW.created_revision,NEW.ordinal,
              NEW.parent_occurrence_ref,NEW.boundary_evidence,NEW.cancel_generation)
             IS DISTINCT FROM
             (OLD.seller_ref,OLD.environment,OLD.occurrence_ref,OLD.incarnation,OLD.component_ref,OLD.purpose,
              OLD.period_start,OLD.period_end,OLD.inputs_digest,OLD.schedule_id,OLD.created_revision,OLD.ordinal,
              OLD.parent_occurrence_ref,OLD.boundary_evidence,OLD.cancel_generation) THEN
              RAISE EXCEPTION 'billium.occurrence.transition_forbidden: immutable columns' USING ERRCODE='55000';
             END IF;
             IF (NEW.state,NEW.rated_basis_ref,NEW.superseded_by_occurrence_ref) IS DISTINCT FROM
                (OLD.state,OLD.rated_basis_ref,OLD.superseded_by_occurrence_ref) AND NOT (
              (OLD.state='planned' AND NEW.state IN ('superseded','cancelled','rated','obligated'))
              OR (OLD.state='rated' AND NEW.state='obligated' AND NEW.rated_basis_ref=OLD.rated_basis_ref)) THEN
              RAISE EXCEPTION 'billium.occurrence.transition_forbidden: % -> %',OLD.state,NEW.state USING ERRCODE='55000';
             END IF;
             RETURN NEW;
            END $$;
            CREATE TRIGGER billium_schedule_occurrence_guard BEFORE INSERT OR UPDATE OR DELETE ON public.billium_schedule_billing_occurrence
             FOR EACH ROW EXECUTE FUNCTION public.billium_schedule_occurrence_guard();
            CREATE TABLE public.billium_schedule_occurrence_amendment (
             id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
             seller_ref text NOT NULL, environment text NOT NULL, occurrence_ref text NOT NULL,
             schedule_revision integer NOT NULL CHECK (schedule_revision>=1),
             kind text NOT NULL CHECK (kind IN ('revision_confirmed','superseded_by','amendment_required','cancelled')),
             detail jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(detail)='object' AND octet_length(detail::text)<=32768)
            );
            CREATE TRIGGER billium_schedule_amendment_immutable BEFORE UPDATE OR DELETE ON public.billium_schedule_occurrence_amendment
             FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TABLE public.billium_schedule_occurrence_outbox (
             id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
             seller_ref text NOT NULL, environment text NOT NULL,
             event_kind text NOT NULL CHECK (event_kind IN ('schedule.occurrence.ensured.v1','schedule.occurrence.amendment_required.v1',
             'schedule.revision.applied.v1','schedule.cancelled.v1','schedule.materialized.v1','schedule.failure_recorded.v1')),
             body jsonb NOT NULL CHECK (jsonb_typeof(body)='object' AND octet_length(body::text)<=32768)
            );
            CREATE TRIGGER billium_schedule_occurrence_outbox_immutable BEFORE UPDATE OR DELETE ON public.billium_schedule_occurrence_outbox
             FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            SQL);
        $runtime = config('billium.connection') ?? config('database.default');
        $username = DB::connection($runtime)->getConfig('username');
        if (! is_string($username) || $username === '') {
            throw new LogicException('Schedule runtime role is required');
        }
        $role = '"'.str_replace('"', '""', $username).'"';
        foreach (['billium_schedule_billing_schedule', 'billium_schedule_billing_schedule_revision', 'billium_schedule_billing_occurrence', 'billium_schedule_occurrence_amendment', 'billium_schedule_occurrence_outbox'] as $table) {
            DB::unprepared('REVOKE ALL ON TABLE public.'.$table.' FROM PUBLIC, '.$role);
            DB::unprepared('GRANT SELECT, INSERT ON TABLE public.'.$table.' TO '.$role);
        }
        DB::unprepared('GRANT UPDATE (current_revision,state,cancel_generation,cursor_ordinal,next_occurrence_at,runnable,claim_token,claim_expires_at,attempts,retry_at,row_version) ON TABLE public.billium_schedule_billing_schedule TO '.$role);
        DB::unprepared('GRANT UPDATE (state,superseded_by_occurrence_ref,rated_basis_ref,row_version) ON TABLE public.billium_schedule_billing_occurrence TO '.$role);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
