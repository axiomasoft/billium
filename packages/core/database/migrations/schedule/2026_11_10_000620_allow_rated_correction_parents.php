<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Rating verifies that a rated parent has a terminal no_charge booking before registering its child.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.billium_schedule_correction_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE p public.billium_schedule_billing_occurrence%ROWTYPE; h public.billium_schedule_correction_head%ROWTYPE;
            BEGIN
             SELECT * INTO p FROM public.billium_schedule_billing_occurrence WHERE id=NEW.parent_occurrence_id;
             IF NOT FOUND OR p.state NOT IN ('obligated','rated') OR p.rated_basis_ref IS NULL
              OR p.seller_ref<>NEW.seller_ref OR p.environment<>NEW.environment
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
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
