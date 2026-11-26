<?php

declare(strict_types=1);

use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        // D22/D23/D45/D56; owner schedule. Host agreement state and grants are never copied here.
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_schedule_component (
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                component_key text COLLATE "C" NOT NULL,
                component_ref text NOT NULL,
                agreement_ref text NOT NULL,
                agreement_revision bigint NOT NULL DEFAULT 0 CHECK (agreement_revision >= 0),
                cancellation_generation bigint NOT NULL DEFAULT 0 CHECK (cancellation_generation >= 0),
                funding_revision bigint NOT NULL DEFAULT 0 CHECK (funding_revision >= 0),
                action text NOT NULL DEFAULT 'apply' CHECK (action IN ('apply','pause','resume')),
                effective_at timestamptz NULL,
                covered_until timestamptz NULL,
                terms jsonb NULL CHECK (jsonb_typeof(terms)='object' AND octet_length(terms::text)<=32768),
                PRIMARY KEY (seller_ref, environment, component_key),
                UNIQUE (seller_ref, environment, component_ref),
                UNIQUE (seller_ref, environment, component_ref, agreement_ref),
                CHECK ((agreement_revision=0 AND terms IS NULL AND effective_at IS NULL AND covered_until IS NULL)
                    OR (agreement_revision>0 AND terms IS NOT NULL AND effective_at IS NOT NULL AND covered_until IS NOT NULL))
            );
            CREATE TABLE public.billium_schedule_quote (
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                quote_ref text NOT NULL,
                component_ref text NOT NULL,
                agreement_ref text NOT NULL,
                agreement_revision bigint NOT NULL CHECK (agreement_revision > 0),
                cancellation_generation bigint NOT NULL CHECK (cancellation_generation >= 0),
                terms_digest text NOT NULL CHECK (terms_digest ~ '^sha256:[a-f0-9]{64}$'),
                period_digest text NOT NULL CHECK (period_digest ~ '^sha256:[a-f0-9]{64}$'),
                quote_digest text NOT NULL CHECK (quote_digest ~ '^sha256:[a-f0-9]{64}$'),
                valid_until timestamptz NOT NULL,
                body jsonb NOT NULL CHECK (jsonb_typeof(body)='object' AND octet_length(body::text)<=32768),
                PRIMARY KEY (seller_ref, environment, quote_ref),
                UNIQUE (seller_ref, environment, quote_ref, component_ref, agreement_ref, agreement_revision, cancellation_generation, terms_digest)
            );
            CREATE TABLE public.billium_schedule_revision (
                seller_ref text NOT NULL,
                environment text NOT NULL,
                component_ref text NOT NULL,
                agreement_ref text NOT NULL,
                agreement_revision bigint NOT NULL CHECK (agreement_revision>0),
                cancellation_generation bigint NOT NULL CHECK (cancellation_generation>=0),
                funding_revision bigint NOT NULL CHECK (funding_revision>0),
                quote_ref text NOT NULL,
                action text NOT NULL CHECK (action IN ('apply','pause','resume')),
                effective_at timestamptz NOT NULL,
                terms_digest text NOT NULL CHECK (terms_digest ~ '^sha256:[a-f0-9]{64}$'),
                body jsonb NOT NULL CHECK (jsonb_typeof(body)='object' AND octet_length(body::text)<=32768),
                receipt jsonb NOT NULL CHECK (jsonb_typeof(receipt)='object' AND octet_length(receipt::text)<=32768),
                PRIMARY KEY (seller_ref, environment, component_ref, agreement_revision),
                UNIQUE (seller_ref, environment, quote_ref),
                FOREIGN KEY (seller_ref,environment,component_ref,agreement_ref)
                    REFERENCES public.billium_schedule_component (seller_ref,environment,component_ref,agreement_ref),
                FOREIGN KEY (seller_ref,environment,quote_ref,component_ref,agreement_ref,agreement_revision,cancellation_generation,terms_digest)
                    REFERENCES public.billium_schedule_quote (seller_ref,environment,quote_ref,component_ref,agreement_ref,agreement_revision,cancellation_generation,terms_digest)
            );
            CREATE TABLE public.billium_schedule_period (
                seller_ref text NOT NULL,
                environment text NOT NULL,
                period_ref text NOT NULL,
                coverage_ref text NOT NULL,
                occurrence_ref text NOT NULL,
                component_ref text NOT NULL,
                purpose text NOT NULL,
                period_start timestamptz NOT NULL,
                period_end timestamptz NOT NULL CHECK (period_end>period_start),
                agreement_ref text NOT NULL,
                agreement_revision bigint NOT NULL CHECK (agreement_revision>0),
                funding_revision bigint NOT NULL CHECK (funding_revision>0),
                period_digest text NOT NULL CHECK (period_digest ~ '^sha256:[a-f0-9]{64}$'),
                terms_digest text NOT NULL CHECK (terms_digest ~ '^sha256:[a-f0-9]{64}$'),
                body jsonb NOT NULL CHECK (jsonb_typeof(body)='object' AND octet_length(body::text)<=32768),
                PRIMARY KEY (seller_ref, environment, period_ref),
                UNIQUE (seller_ref,environment,component_ref,purpose,period_start,period_end),
                UNIQUE (seller_ref,environment,coverage_ref),
                UNIQUE (seller_ref,environment,occurrence_ref),
                FOREIGN KEY (seller_ref,environment,component_ref,agreement_revision)
                    REFERENCES public.billium_schedule_revision (seller_ref,environment,component_ref,agreement_revision)
                    DEFERRABLE INITIALLY DEFERRED
            );
            CREATE TRIGGER billium_schedule_quote_immutable BEFORE UPDATE OR DELETE ON public.billium_schedule_quote
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_schedule_revision_immutable BEFORE UPDATE OR DELETE ON public.billium_schedule_revision
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_schedule_period_immutable BEFORE UPDATE OR DELETE ON public.billium_schedule_period
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            SQL);
        $runtime = config('billium.connection') ?? config('database.default');
        $username = DB::connection($runtime)->getConfig('username');
        if (! is_string($username) || $username === '') {
            throw new LogicException('Schedule runtime role is required');
        }
        $role = '"'.str_replace('"', '""', $username).'"';
        foreach (['component', 'quote', 'revision', 'period'] as $table) {
            DB::unprepared('REVOKE ALL ON TABLE public.billium_schedule_'.$table.' FROM PUBLIC, '.$role);
            DB::unprepared('GRANT SELECT, INSERT ON TABLE public.billium_schedule_'.$table.' TO '.$role);
        }
        DB::unprepared('GRANT UPDATE (agreement_revision,cancellation_generation,funding_revision,action,effective_at,covered_until,terms)
            ON TABLE public.billium_schedule_component TO '.$role);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
