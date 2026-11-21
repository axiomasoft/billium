<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Prune proves an event has no undelivered delivery. The planner hashes that proof over every delivery row,
            -- so each prune batch read the whole delivered history; open deliveries are the small, hot part.
            CREATE INDEX billium_outbox_delivery_open_event
                ON public.billium_outbox_delivery (event_id) WHERE status <> 'delivered';
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
