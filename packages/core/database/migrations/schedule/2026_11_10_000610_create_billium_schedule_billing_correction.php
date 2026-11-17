<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_schedule_correction_head (
             seller_ref text NOT NULL, environment text NOT NULL, parent_occurrence_ref text NOT NULL,
             head_revision integer NOT NULL DEFAULT 0 CHECK (head_revision>=0), head_frontier_digest text NULL,
             row_version bigint NOT NULL DEFAULT 1 CHECK (row_version>=1),
             PRIMARY KEY(seller_ref,environment,parent_occurrence_ref),
             CHECK ((head_revision=0)=(head_frontier_digest IS NULL))
            );
            CREATE TABLE public.billium_schedule_billing_correction (
             id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
             seller_ref text NOT NULL, environment text NOT NULL, correction_ref text NOT NULL,
             purpose text NOT NULL DEFAULT 'correction' CHECK (purpose='correction'),
             parent_occurrence_id bigint NOT NULL REFERENCES public.billium_schedule_billing_occurrence(id),
             parent_occurrence_ref text NOT NULL, original_period_ref text NOT NULL,
             original_period_start timestamptz NOT NULL, original_period_end timestamptz NOT NULL CHECK (original_period_end>original_period_start),
             correction_revision integer NOT NULL CHECK (correction_revision>=1), predecessor_revision integer NULL,
             predecessor_rated_basis_ref text NOT NULL CHECK (length(predecessor_rated_basis_ref) BETWEEN 1 AND 200),
             source_frontier_digest text NOT NULL CHECK (length(source_frontier_digest) BETWEEN 1 AND 200),
             tax_correction_policy_ref text NULL, tax_correction_policy_version text NULL,
             reason_code text NOT NULL CHECK (length(reason_code) BETWEEN 1 AND 200),
             obligation_basis_ref text NOT NULL CHECK (length(obligation_basis_ref) BETWEEN 1 AND 200),
             registered_digest text NOT NULL,
             UNIQUE(seller_ref,environment,parent_occurrence_ref,correction_revision),
             UNIQUE(seller_ref,environment,correction_ref), UNIQUE(seller_ref,environment,obligation_basis_ref),
             UNIQUE(seller_ref,environment,parent_occurrence_ref,source_frontier_digest),
             CHECK ((correction_revision=1)=(predecessor_revision IS NULL)),
             CHECK (predecessor_revision IS NULL OR predecessor_revision=correction_revision-1),
             CHECK ((tax_correction_policy_ref IS NULL)=(tax_correction_policy_version IS NULL))
            );
            CREATE TRIGGER billium_schedule_correction_immutable BEFORE UPDATE OR DELETE ON public.billium_schedule_billing_correction
             FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE OR REPLACE FUNCTION public.billium_schedule_correction_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE p public.billium_schedule_billing_occurrence%ROWTYPE; h public.billium_schedule_correction_head%ROWTYPE;
            BEGIN
             SELECT * INTO p FROM public.billium_schedule_billing_occurrence WHERE id=NEW.parent_occurrence_id;
             IF NOT FOUND OR p.state<>'obligated' OR p.seller_ref<>NEW.seller_ref OR p.environment<>NEW.environment
              OR p.occurrence_ref<>NEW.parent_occurrence_ref
              OR p.period_start<>NEW.original_period_start OR p.period_end<>NEW.original_period_end THEN
              RAISE EXCEPTION 'billium.correction.parent_invalid' USING ERRCODE='23514';
             END IF;
             SELECT * INTO h FROM public.billium_schedule_correction_head
              WHERE seller_ref=NEW.seller_ref AND environment=NEW.environment AND parent_occurrence_ref=NEW.parent_occurrence_ref;
             IF NOT FOUND OR h.head_revision+1<>NEW.correction_revision THEN
              RAISE EXCEPTION 'billium.correction.frontier_stale' USING ERRCODE='23514';
             END IF;
             IF (NEW.correction_revision=1 AND NEW.predecessor_rated_basis_ref<>p.rated_basis_ref) OR
              (NEW.predecessor_revision IS NOT NULL AND NOT EXISTS (
               SELECT 1 FROM public.billium_schedule_billing_correction c WHERE c.parent_occurrence_id=p.id
                AND c.correction_revision=NEW.predecessor_revision AND c.source_frontier_digest=h.head_frontier_digest
                )) THEN
              RAISE EXCEPTION 'billium.correction.frontier_stale: predecessor' USING ERRCODE='23514';
             END IF;
             RETURN NEW;
            END $$;
            CREATE TRIGGER billium_schedule_correction_guard BEFORE INSERT ON public.billium_schedule_billing_correction
             FOR EACH ROW EXECUTE FUNCTION public.billium_schedule_correction_guard();
            SQL);
        $runtime = config('billium.connection') ?? config('database.default');
        $username = DB::connection($runtime)->getConfig('username');
        if (! is_string($username) || $username === '') {
            throw new LogicException('Schedule runtime role is required');
        }
        $role = '"'.str_replace('"', '""', $username).'"';
        foreach (['billium_schedule_correction_head', 'billium_schedule_billing_correction'] as $table) {
            DB::unprepared('REVOKE ALL ON TABLE public.'.$table.' FROM PUBLIC, '.$role);
            DB::unprepared('GRANT SELECT, INSERT ON TABLE public.'.$table.' TO '.$role);
        }
        DB::unprepared('GRANT UPDATE (head_revision,head_frontier_digest,row_version) ON TABLE public.billium_schedule_correction_head TO '.$role);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
