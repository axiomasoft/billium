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
            CREATE TABLE public.billium_feed_observation_root_scope (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                root_key text NOT NULL UNIQUE CHECK (root_key ~ '^sha256:[0-9a-f]{64}$'),
                channel text NOT NULL CHECK (channel <> ''),
                installation_ref text NOT NULL CHECK (installation_ref <> ''),
                seller_ref text NOT NULL CHECK (seller_ref <> ''),
                environment text NOT NULL CHECK (environment <> ''),
                source_authority text NOT NULL CHECK (source_authority <> ''),
                provider_account_ref text NOT NULL CHECK (provider_account_ref <> ''),
                root_operation_ref text NOT NULL CHECK (root_operation_ref <> ''),
                intent_owner_namespace text NOT NULL CHECK (intent_owner_namespace <> ''),
                first_source_lineage text NOT NULL CHECK (first_source_lineage <> ''),
                first_partition_ref text NOT NULL CHECK (first_partition_ref <> ''),
                first_proof_digest text NOT NULL CHECK (first_proof_digest ~ '^sha256:[0-9a-f]{64}$')
            );
            CREATE TABLE public.billium_feed_observation_scope_link (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                scope_id bigint NOT NULL REFERENCES public.billium_feed_observation_root_scope(id),
                inbox_id bigint UNIQUE REFERENCES public.billium_inbox_message(id),
                partition_id bigint UNIQUE REFERENCES public.billium_feed_partition(id),
                bootstrap_session_id bigint UNIQUE REFERENCES public.billium_feed_bootstrap_session(id),
                proof_digest text NOT NULL CHECK (proof_digest ~ '^sha256:[0-9a-f]{64}$'),
                CHECK (num_nonnulls(inbox_id,partition_id,bootstrap_session_id) = 1)
            );
            CREATE INDEX billium_feed_scope_link_root ON public.billium_feed_observation_scope_link(scope_id);
            CREATE TRIGGER billium_feed_root_scope_immutable BEFORE UPDATE OR DELETE ON public.billium_feed_observation_root_scope
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TRIGGER billium_feed_scope_link_immutable BEFORE UPDATE OR DELETE ON public.billium_feed_observation_scope_link
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            SQL);
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_feed_observation_root_scope');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_feed_observation_scope_link');
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
