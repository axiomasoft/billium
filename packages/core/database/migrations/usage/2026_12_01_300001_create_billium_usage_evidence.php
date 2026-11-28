<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

/**
 * Usage evidence: streams, metrics and immutable normalized usage facts.
 */
return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Immutable normalized evidence, never a rating/debit writer.
            CREATE TABLE public.billium_usage_stream (
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                commercial_basis_ref text NOT NULL,
                mode text NOT NULL CHECK (mode IN ('gross_usage','normalized_net_billable')),
                normalization_ref text NOT NULL,
                PRIMARY KEY (seller_ref, environment, commercial_basis_ref),
                UNIQUE (seller_ref, environment, commercial_basis_ref, mode)
            );

            CREATE TRIGGER billium_usage_stream_immutable BEFORE UPDATE OR DELETE ON public.billium_usage_stream
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE TABLE public.billium_usage_metric (
                seller_ref text NOT NULL,
                environment text NOT NULL,
                metric_ref text NOT NULL,
                metric_version text NOT NULL,
                digest text NOT NULL CHECK (digest ~ '^sha256:[0-9a-f]{64}$'),
                unit text NOT NULL,
                model text NOT NULL,
                commercial_basis_ref text NOT NULL,
                mode text NOT NULL,
                body jsonb NOT NULL CHECK (jsonb_typeof(body)='object' AND octet_length(body::text)<=65536),
                aggregation_semantics text GENERATED ALWAYS AS
                    (CASE model WHEN 'additive_sum' THEN 'additive' WHEN 'additive_count' THEN 'additive'
                    WHEN 'gauge' THEN 'gauge' WHEN 'exact_distinct' THEN 'distinct_exact' END) STORED NOT NULL,
                aggregation_algorithm text GENERATED ALWAYS AS
                    (CASE model WHEN 'additive_sum' THEN 'additive_sum' WHEN 'additive_count' THEN 'additive_sum'
                    WHEN 'exact_distinct' THEN 'distinct_exact_union'
                    WHEN 'gauge' THEN CASE jsonb_extract_path_text(body,'model','sampleReducer')
                    WHEN 'latest' THEN 'gauge_latest_as_of' WHEN 'latest_sample' THEN 'gauge_latest_as_of'
                    WHEN 'latest_as_of' THEN 'gauge_latest_as_of' WHEN 'max' THEN 'gauge_max'
                    WHEN 'max_sample' THEN 'gauge_max' WHEN 'time_weighted' THEN 'gauge_time_weighted'
                    END END) STORED NOT NULL,
                PRIMARY KEY (seller_ref, environment, metric_ref, metric_version),
                FOREIGN KEY (seller_ref, environment, commercial_basis_ref, mode)
                    REFERENCES public.billium_usage_stream (seller_ref, environment, commercial_basis_ref, mode),
                -- Relational semantics agree with the retained definition and snapshot policy.
                -- The composite key permits registered extension algorithm versions, including version 2.
                CONSTRAINT billium_usage_metric_model_ck
                    CHECK (model IN ('additive_sum','additive_count','gauge','exact_distinct')),
                CONSTRAINT billium_usage_metric_model_body_ck
                    CHECK (jsonb_extract_path_text(body,'model','id') IS NOT NULL
                    AND model=jsonb_extract_path_text(body,'model','id')),
                CONSTRAINT billium_usage_metric_semantics_uq
                    UNIQUE (seller_ref,environment,metric_ref,metric_version,digest,aggregation_semantics,aggregation_algorithm)
            );

            CREATE TRIGGER billium_usage_metric_immutable BEFORE UPDATE OR DELETE ON public.billium_usage_metric
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE TABLE public.billium_usage_fact (
                seller_ref text NOT NULL,
                environment text NOT NULL,
                source text NOT NULL,
                fact_id text NOT NULL,
                revision bigint NOT NULL CHECK (revision>=1),
                digest text NOT NULL CHECK (digest ~ '^sha256:[0-9a-f]{64}$'),
                metric_ref text NOT NULL,
                metric_version text NOT NULL,
                subject_ref text NOT NULL,
                customer_ref text NOT NULL,
                billing_account_ref text NOT NULL,
                component_ref text NOT NULL,
                agreement_ref text NOT NULL,
                agreement_revision bigint NOT NULL CHECK (agreement_revision>=1),
                attribution_version text NOT NULL,
                mapping_ref text NOT NULL CHECK (mapping_ref<>''),
                valid_from text COLLATE "C" NOT NULL,
                valid_until text COLLATE "C" NOT NULL CHECK (valid_until>valid_from),
                period_ref text NOT NULL CHECK (period_ref<>''),
                period_start text COLLATE "C" NOT NULL,
                period_end text COLLATE "C" NOT NULL CHECK (period_end>period_start),
                -- Fixed-width UTC nanoseconds retain the contract's precision and byte-sort in event order.
                event_at text COLLATE "C" NOT NULL CHECK (event_at ~ '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{9}$'),
                interval_start text COLLATE "C" NOT NULL,
                interval_end text COLLATE "C" NOT NULL CHECK (interval_end>interval_start),
                received_at text COLLATE "C" NOT NULL,
                quantity numeric(33,9) NULL CHECK (quantity>=0),
                supersedes_revision bigint NULL,
                correction_ref text NULL,
                body jsonb NOT NULL CHECK (jsonb_typeof(body)='object' AND octet_length(body::text)<=65536),
                receipt jsonb NOT NULL CHECK (jsonb_typeof(receipt)='object' AND octet_length(receipt::text)<=65536),
                -- Usage owns snapshots, evidence and its internal notifications.
                ingest_seq bigint GENERATED ALWAYS AS IDENTITY,
                PRIMARY KEY (seller_ref, environment, source, fact_id, revision),
                UNIQUE (seller_ref, environment, source, fact_id, supersedes_revision),
                UNIQUE (seller_ref, environment, correction_ref),
                CHECK ((revision=1 AND supersedes_revision IS NULL AND correction_ref IS NULL)
                    OR (revision>1 AND supersedes_revision=revision-1 AND correction_ref IS NOT NULL)),
                CHECK (interval_start>=valid_from AND interval_end<=valid_until
                    AND interval_start>=period_start AND interval_end<=period_end),
                FOREIGN KEY (seller_ref, environment, metric_ref, metric_version)
                    REFERENCES public.billium_usage_metric (seller_ref, environment, metric_ref, metric_version),
                FOREIGN KEY (seller_ref, environment, source, fact_id, supersedes_revision)
                    REFERENCES public.billium_usage_fact (seller_ref, environment, source, fact_id, revision)
            );

            CREATE INDEX billium_usage_subject_window ON public.billium_usage_fact
                (seller_ref, subject_ref, metric_version, event_at, fact_id, revision, source);

            CREATE INDEX billium_usage_effective_revision ON public.billium_usage_fact
                (seller_ref, environment, source, fact_id, revision DESC);

            CREATE TRIGGER billium_usage_fact_immutable BEFORE UPDATE OR DELETE ON public.billium_usage_fact
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            SQL);

        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_usage_stream');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_usage_metric');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_usage_fact');
    }

    public function down(): never
    {
        throw new LogicException('Billium migrations are forward-only.');
    }
};
