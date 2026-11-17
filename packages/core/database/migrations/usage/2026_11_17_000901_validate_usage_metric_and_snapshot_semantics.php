<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.billium_usage_metric
                VALIDATE CONSTRAINT billium_usage_metric_model_ck,
                VALIDATE CONSTRAINT billium_usage_metric_model_body_ck;
            ALTER TABLE public.billium_usage_aggregation_policy
                VALIDATE CONSTRAINT billium_usage_policy_metric_semantics_fk;
            ALTER TABLE public.billium_usage_aggregate_snapshot
                VALIDATE CONSTRAINT billium_usage_snapshot_policy_algorithm_fk;
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
