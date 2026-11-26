<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        // PF-10/COV-086..088: immutable event content written in the owning root Tx; (tx_id, id) is the D52 cursor.
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_outbox_event (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                event_id text NOT NULL CHECK (event_id <> '' AND length(event_id) <= 200),
                seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
                environment text NOT NULL CHECK (environment <> '' AND length(environment) <= 200),
                type text NOT NULL CHECK (type ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$' AND length(type) <= 200),
                schema_version text NOT NULL CHECK (schema_version ~ '^[a-z][a-z0-9_.-]*\.v[0-9]+$' AND length(schema_version) <= 200),
                aggregate_ref text NOT NULL CHECK (aggregate_ref <> '' AND length(aggregate_ref) <= 200),
                aggregate_revision bigint NOT NULL CHECK (aggregate_revision >= 0),
                occurred_at timestamptz NOT NULL,
                digest text NOT NULL CHECK (digest ~ '^sha256:[0-9a-f]{64}$'),
                payload jsonb NOT NULL CHECK (jsonb_typeof(payload) = 'object' AND octet_length(payload::text) <= 65536),
                tx_id xid8 NOT NULL DEFAULT pg_current_xact_id(),
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT billium_outbox_event_event_id_key UNIQUE (event_id)
            );
            CREATE INDEX billium_outbox_event_cursor ON public.billium_outbox_event (tx_id, id);
            CREATE TRIGGER billium_outbox_event_append_only BEFORE UPDATE OR DELETE ON public.billium_outbox_event
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            SQL);
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_outbox_event');
    }

    public function down(): void
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
