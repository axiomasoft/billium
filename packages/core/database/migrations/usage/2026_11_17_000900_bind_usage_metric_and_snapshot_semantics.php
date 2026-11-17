<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // COV-106: relational semantics agree with the retained definition and snapshot policy.
        // The composite key permits registered extension algorithm versions, including version 2.
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.billium_usage_metric
                ADD CONSTRAINT billium_usage_metric_model_ck
                    CHECK (model IN ('additive_sum','additive_count','gauge','exact_distinct')) NOT VALID,
                ADD CONSTRAINT billium_usage_metric_model_body_ck
                    CHECK (jsonb_extract_path_text(body,'model','id') IS NOT NULL
                        AND model=jsonb_extract_path_text(body,'model','id')) NOT VALID,
                ADD COLUMN aggregation_semantics text GENERATED ALWAYS AS
                    (CASE model WHEN 'additive_sum' THEN 'additive' WHEN 'additive_count' THEN 'additive'
                        WHEN 'gauge' THEN 'gauge' WHEN 'exact_distinct' THEN 'distinct_exact' END) STORED NOT NULL,
                ADD COLUMN aggregation_algorithm text GENERATED ALWAYS AS
                    (CASE model WHEN 'additive_sum' THEN 'additive_sum' WHEN 'additive_count' THEN 'additive_sum'
                        WHEN 'exact_distinct' THEN 'distinct_exact_union'
                        WHEN 'gauge' THEN CASE jsonb_extract_path_text(body,'model','sampleReducer')
                            WHEN 'latest' THEN 'gauge_latest_as_of' WHEN 'latest_sample' THEN 'gauge_latest_as_of'
                            WHEN 'latest_as_of' THEN 'gauge_latest_as_of' WHEN 'max' THEN 'gauge_max'
                            WHEN 'max_sample' THEN 'gauge_max' WHEN 'time_weighted' THEN 'gauge_time_weighted'
                        END END) STORED NOT NULL,
                ADD CONSTRAINT billium_usage_metric_semantics_uq
                    UNIQUE (seller_ref,environment,metric_ref,metric_version,digest,aggregation_semantics,aggregation_algorithm);
            ALTER TABLE public.billium_usage_aggregation_policy
                ADD CONSTRAINT billium_usage_policy_metric_semantics_fk
                    FOREIGN KEY (seller_ref,environment,metric_ref,metric_version,metric_definition_digest,semantics,algorithm_id)
                    REFERENCES public.billium_usage_metric
                        (seller_ref,environment,metric_ref,metric_version,digest,aggregation_semantics,aggregation_algorithm) NOT VALID,
                ADD CONSTRAINT billium_usage_policy_algorithm_uq
                    UNIQUE (seller_ref,environment,metric_ref,metric_version,algorithm_id,algorithm_version);
            ALTER TABLE public.billium_usage_aggregate_snapshot
                ADD CONSTRAINT billium_usage_snapshot_policy_algorithm_fk
                    FOREIGN KEY (seller_ref,environment,metric_ref,metric_version,algorithm_id,algorithm_version)
                    REFERENCES public.billium_usage_aggregation_policy
                        (seller_ref,environment,metric_ref,metric_version,algorithm_id,algorithm_version) NOT VALID;
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
