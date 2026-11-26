<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        // REQ-FAIL-04: the same event identity with another content digest keeps the original untouched and this delivery here.
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_inbox_conflict (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                inbox_id bigint NOT NULL REFERENCES public.billium_inbox_message (id),
                conflicting_digest text NOT NULL CHECK (conflicting_digest ~ '^sha256:[0-9a-f]{64}$'),
                payload jsonb NOT NULL CHECK (jsonb_typeof(payload) = 'object' AND octet_length(payload::text) <= 65536),
                received_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT billium_inbox_conflict_delivery_key UNIQUE (inbox_id, conflicting_digest)
            );
            CREATE TRIGGER billium_inbox_conflict_append_only BEFORE UPDATE OR DELETE ON public.billium_inbox_conflict
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            SQL);
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_inbox_conflict');
    }

    public function down(): void
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
