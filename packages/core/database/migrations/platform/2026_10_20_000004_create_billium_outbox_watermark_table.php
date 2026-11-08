<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // D52: conservative replay floor per scope, not a proof that deleted rows formed a prefix; prune is P3.4.
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_outbox_watermark (
                id bigint PRIMARY KEY CHECK (id >= 1),
                scope text NOT NULL CHECK (scope <> '' AND length(scope) <= 200),
                pruned_tx_id xid8 NOT NULL DEFAULT '0',
                pruned_id bigint NOT NULL DEFAULT 0 CHECK (pruned_id >= 0),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT billium_outbox_watermark_scope_key UNIQUE (scope)
            );
            INSERT INTO public.billium_outbox_watermark (id, scope) VALUES (1, 'installation');
            SQL);
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_outbox_watermark');
    }

    public function down(): void
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
