<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Every snapshot read asks for its own reproducibility events; without an index each read scanned the
            -- events of every snapshot.
            CREATE INDEX billium_usage_aggregate_reproducibility_event_snapshot
                ON public.billium_usage_aggregate_reproducibility_event (snapshot_id);
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
