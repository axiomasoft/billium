<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- A feed reads one seller and environment in cursor order; the bare cursor index made every reader
            -- filter out the events of all other sellers sharing the outbox.
            CREATE INDEX billium_outbox_event_scope_cursor
                ON public.billium_outbox_event (seller_ref,environment,tx_id,id);
            -- The scope key repeats seller and environment. Without correlated statistics the planner multiplies their
            -- selectivities, expects only a handful of matches and sorts the whole seller instead of walking the index.
            CREATE STATISTICS public.billium_outbox_event_scope_stats (dependencies, mcv)
                ON seller_ref, environment, (source_scope::jsonb) FROM public.billium_outbox_event;
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
