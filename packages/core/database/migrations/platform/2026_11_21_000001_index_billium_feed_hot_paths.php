<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE INDEX billium_outbox_event_scope_cursor ON public.billium_outbox_event ((source_scope::jsonb), tx_id, id);
            CREATE INDEX billium_inbox_message_pending_op_idx ON public.billium_inbox_message (seller_ref, environment, channel, source_authority, operation_ref, ((payload->>'installationRef')), operation_revision DESC, id DESC) WHERE status IN ('ready', 'parked_gap', 'parked_dependency');
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
