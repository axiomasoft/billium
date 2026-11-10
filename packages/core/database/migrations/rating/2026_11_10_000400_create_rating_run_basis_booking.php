<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // D19/D22/D23/D27/D56/D60: rating owns retained inputs and the insert-once booking map.
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_rating_run (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                run_ref text NOT NULL,
                occurrence_ref text NOT NULL,
                billing_account_ref text NOT NULL,
                inputs_digest text NOT NULL,
                agreement_revision text NOT NULL,
                period_start_micros bigint NOT NULL,
                period_end_micros bigint NOT NULL CHECK (period_end_micros > period_start_micros),
                frozen_inputs jsonb NOT NULL CHECK (jsonb_typeof(frozen_inputs)='object' AND octet_length(frozen_inputs::text)<=1048576),
                UNIQUE (seller_ref,environment,run_ref),
                UNIQUE (seller_ref,environment,inputs_digest),
                UNIQUE (seller_ref,environment,id)
            );
            CREATE TABLE public.billium_rating_rated_basis (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                basis_ref text NOT NULL CHECK (basis_ref ~ '^rb_[0-9a-f]{48}$'),
                run_id bigint NOT NULL,
                occurrence_ref text NOT NULL,
                kind text NOT NULL CHECK (kind IN ('original','correction')),
                correction_revision integer NULL CHECK (correction_revision>=1),
                predecessor_basis_ref text NULL,
                currency char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
                currency_exponent smallint NOT NULL CHECK (currency_exponent BETWEEN 0 AND 4),
                net_minor bigint NOT NULL, tax_minor bigint NOT NULL, gross_minor bigint NOT NULL,
                charge_kind text NOT NULL CHECK (charge_kind IN ('charge','no_charge')),
                no_charge_reason text NULL,
                inputs_digest text NOT NULL, rated_digest text NOT NULL,
                UNIQUE (seller_ref,environment,basis_ref),
                UNIQUE (seller_ref,environment,id),
                FOREIGN KEY (seller_ref,environment,run_id) REFERENCES public.billium_rating_run(seller_ref,environment,id),
                CHECK (gross_minor::numeric=net_minor::numeric+tax_minor::numeric),
                CHECK ((kind='original')=(correction_revision IS NULL)),
                CHECK ((kind='original')=(predecessor_basis_ref IS NULL)),
                CHECK ((charge_kind='no_charge')=(no_charge_reason IS NOT NULL)),
                CHECK (charge_kind<>'no_charge' OR (net_minor=0 AND tax_minor=0 AND no_charge_reason<>'')),
                CHECK (kind<>'original' OR (net_minor>=0 AND tax_minor>=0 AND (charge_kind<>'charge' OR gross_minor>0)))
            );
            CREATE UNIQUE INDEX billium_rating_basis_original_uq ON public.billium_rating_rated_basis(seller_ref,environment,occurrence_ref) WHERE kind='original';
            CREATE UNIQUE INDEX billium_rating_basis_correction_uq ON public.billium_rating_rated_basis(seller_ref,environment,occurrence_ref,correction_revision) WHERE kind='correction';
            CREATE TABLE public.billium_rating_rated_line (
                basis_id bigint NOT NULL REFERENCES public.billium_rating_rated_basis(id),
                line_no smallint NOT NULL CHECK (line_no>=1),
                component_ref text NOT NULL,
                metric_ref text NOT NULL, metric_version text NOT NULL,
                price_ref text NOT NULL, price_version integer NOT NULL CHECK (price_version>0),
                strategy_id text NOT NULL, strategy_version integer NOT NULL,
                terms_digest text NOT NULL,
                aggregate_snapshot_digest text NOT NULL,
                window_start_micros bigint NOT NULL, window_end_micros bigint NOT NULL CHECK (window_end_micros>window_start_micros),
                quantity numeric(38,12) NOT NULL CHECK (quantity>=0),
                quantity_scale smallint NOT NULL CHECK (quantity_scale BETWEEN 0 AND 12),
                raw_amount numeric(38,24) NOT NULL, commitment_adjustment numeric(38,24) NOT NULL, priced_amount numeric(38,24) NOT NULL,
                commitment_scope_digest text NULL,
                rounding_stage text NOT NULL, rounding_mode text NOT NULL, rounding_policy_version text NOT NULL, exponent_version text NOT NULL,
                tax_snapshot_ref text NOT NULL, jurisdiction text NOT NULL, tax_code text NOT NULL, tax_mode text NOT NULL, tax_rate numeric(18,8) NOT NULL,
                net_minor bigint NOT NULL, tax_minor bigint NOT NULL, gross_minor bigint NOT NULL, residual_minor bigint NOT NULL,
                intermediates jsonb NOT NULL CHECK (jsonb_typeof(intermediates)='object'),
                predecessor_basis_ref text NULL,
                line_snapshot jsonb NOT NULL CHECK (jsonb_typeof(line_snapshot)='object'),
                PRIMARY KEY(basis_id,line_no),
                CHECK (gross_minor::numeric=net_minor::numeric+tax_minor::numeric)
            );
            CREATE INDEX billium_rating_commitment_scope_idx ON public.billium_rating_rated_line(commitment_scope_digest,basis_id) WHERE commitment_scope_digest IS NOT NULL;
            CREATE TABLE public.billium_rating_booking (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL, environment text NOT NULL,
                basis_id bigint NOT NULL UNIQUE,
                outcome text NOT NULL CHECK (outcome IN ('booked','no_charge')),
                obligation_ref text NULL, obligation_basis_ref text NULL,
                FOREIGN KEY (seller_ref,environment,basis_id) REFERENCES public.billium_rating_rated_basis(seller_ref,environment,id),
                CHECK ((outcome='booked')=(obligation_ref IS NOT NULL)),
                CHECK ((outcome='booked')=(obligation_basis_ref IS NOT NULL))
            );
            CREATE UNIQUE INDEX billium_rating_booking_obligation_uq ON public.billium_rating_booking(seller_ref,environment,obligation_basis_ref) WHERE obligation_basis_ref IS NOT NULL;
            CREATE TABLE public.billium_rating_source_frontier (
                seller_ref text NOT NULL, environment text NOT NULL CHECK(environment IN ('test','live')),
                frontier_key text COLLATE "C" NOT NULL,
                occurrence_ref text NULL,
                head_basis_ref text NULL, head_digest text NOT NULL DEFAULT '',
                correction_head_revision integer NOT NULL DEFAULT 0 CHECK(correction_head_revision>=0),
                row_version bigint NOT NULL DEFAULT 0 CHECK(row_version>=0),
                PRIMARY KEY(seller_ref,environment,frontier_key),
                UNIQUE(seller_ref,environment,occurrence_ref),
                CHECK ((row_version=0)=(head_basis_ref IS NULL))
            );
            CREATE OR REPLACE FUNCTION public.billium_rating_frontier_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'billium.rating.frontier_immutable' USING ERRCODE='55000'; END IF;
                IF (NEW.seller_ref,NEW.environment,NEW.frontier_key,NEW.occurrence_ref) IS DISTINCT FROM (OLD.seller_ref,OLD.environment,OLD.frontier_key,OLD.occurrence_ref)
                   OR NEW.row_version<>OLD.row_version+1 OR NEW.head_digest='' OR NEW.head_basis_ref IS NULL
                   OR NEW.correction_head_revision<OLD.correction_head_revision
                THEN RAISE EXCEPTION 'billium.rating.frontier_cas_required' USING ERRCODE='55000'; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER billium_rating_frontier_guard BEFORE UPDATE OR DELETE ON public.billium_rating_source_frontier FOR EACH ROW EXECUTE FUNCTION public.billium_rating_frontier_guard();
            CREATE TABLE public.billium_rating_event (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL, environment text NOT NULL,
                basis_id bigint NOT NULL,
                event_type text NOT NULL CHECK(event_type IN ('rating.basis.booked.v1','rating.basis.frozen.v1')),
                payload jsonb NOT NULL CHECK(jsonb_typeof(payload)='object'),
                FOREIGN KEY(seller_ref,environment,basis_id) REFERENCES public.billium_rating_rated_basis(seller_ref,environment,id),
                UNIQUE(basis_id,event_type)
            );
            -- Rating-owned additive schema for the P4.8 writer; P4.7 never creates a correction.
            CREATE TABLE public.billium_rating_correction (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL, environment text NOT NULL,
                correction_ref text NOT NULL,
                correction_kind text NOT NULL CHECK(correction_kind IN ('late_usage','partial_cancel')),
                parent_occurrence_ref text NOT NULL,
                correction_revision integer NOT NULL CHECK(correction_revision>=1),
                predecessor_basis_ref text NOT NULL,
                basis_id bigint NOT NULL,
                source_frontier_digest text NOT NULL,
                tax_policy_ref text NULL, tax_policy_version text NULL, tax_snapshot_ref text NULL,
                reason_code text NOT NULL,
                delta_net_minor bigint NOT NULL, delta_tax_minor bigint NOT NULL, delta_gross_minor bigint NOT NULL,
                outcome text NOT NULL CHECK(outcome IN ('obligation','credit_note','none')),
                FOREIGN KEY(seller_ref,environment,basis_id) REFERENCES public.billium_rating_rated_basis(seller_ref,environment,id),
                UNIQUE(seller_ref,environment,correction_ref),
                UNIQUE(seller_ref,environment,parent_occurrence_ref,correction_revision),
                UNIQUE(seller_ref,environment,parent_occurrence_ref,source_frontier_digest),
                CHECK(delta_gross_minor::numeric=delta_net_minor::numeric+delta_tax_minor::numeric),
                CHECK((outcome='obligation')=(delta_gross_minor>0)),
                CHECK((outcome='credit_note')=(delta_gross_minor<0)),
                CHECK((outcome='none')=(delta_gross_minor=0))
            );
            CREATE TABLE public.billium_rating_correction_booking (
                correction_id bigint PRIMARY KEY REFERENCES public.billium_rating_correction(id),
                obligation_ref text NULL, credit_note_ref text NULL,
                release_policy text NULL CHECK(release_policy IN ('open_only','reverse_allocations')),
                CHECK(NOT(obligation_ref IS NOT NULL AND credit_note_ref IS NOT NULL)),
                CHECK((credit_note_ref IS NULL)=(release_policy IS NULL))
            );
            CREATE TRIGGER billium_rating_run_immutable BEFORE UPDATE OR DELETE ON public.billium_rating_run FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_rating_basis_immutable BEFORE UPDATE OR DELETE ON public.billium_rating_rated_basis FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_rating_line_immutable BEFORE UPDATE OR DELETE ON public.billium_rating_rated_line FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_rating_booking_immutable BEFORE UPDATE OR DELETE ON public.billium_rating_booking FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_rating_event_immutable BEFORE UPDATE OR DELETE ON public.billium_rating_event FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_rating_correction_immutable BEFORE UPDATE OR DELETE ON public.billium_rating_correction FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_rating_correction_booking_immutable BEFORE UPDATE OR DELETE ON public.billium_rating_correction_booking FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            SQL);
        $runtime = config('billium.connection') ?? config('database.default');
        $username = DB::connection($runtime)->getConfig('username');
        if (! is_string($username) || $username === '') {
            throw new LogicException('Rating runtime role is required');
        }
        $role = '"'.str_replace('"', '""', $username).'"';
        foreach (['run', 'rated_basis', 'rated_line', 'booking', 'source_frontier', 'event', 'correction', 'correction_booking'] as $table) {
            DB::unprepared('REVOKE ALL ON TABLE public.billium_rating_'.$table.' FROM PUBLIC, '.$role);
            DB::unprepared('GRANT SELECT, INSERT ON TABLE public.billium_rating_'.$table.' TO '.$role);
        }
        DB::unprepared('GRANT UPDATE (head_basis_ref,head_digest,correction_head_revision,row_version) ON TABLE public.billium_rating_source_frontier TO '.$role);
        DB::unprepared('REVOKE ALL ON FUNCTION public.billium_rating_frontier_guard() FROM PUBLIC, '.$role);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
