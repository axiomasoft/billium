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
            CREATE TABLE public.billium_feed_observation_command (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK (installation_ref <> ''),
                command_ref text NOT NULL CHECK (command_ref <> ''),
                binding_digest text NOT NULL CHECK (binding_digest ~ '^sha256:[0-9a-f]{64}$'),
                proof_digest text NOT NULL CHECK (proof_digest ~ '^sha256:[0-9a-f]{64}$'),
                intent_owner_namespace text NOT NULL CHECK (intent_owner_namespace <> ''),
                scope_id bigint REFERENCES public.billium_feed_observation_root_scope(id),
                disposition text NOT NULL CHECK (disposition IN ('received','conflict')),
                payload jsonb NOT NULL CHECK (jsonb_typeof(payload)='object'),
                CHECK ((disposition='received')=(scope_id IS NOT NULL)),
                UNIQUE (installation_ref,command_ref,binding_digest)
            );
            CREATE UNIQUE INDEX billium_observation_received_command_key
                ON public.billium_feed_observation_command(installation_ref,command_ref) WHERE disposition='received';
            CREATE TRIGGER billium_feed_observation_command_immutable BEFORE UPDATE OR DELETE ON public.billium_feed_observation_command
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            SQL);
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_feed_observation_command');
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
