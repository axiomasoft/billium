<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- A payment status read asks for the unresolved discrepancies of one charge and its refunds. Discrepancies
            -- were indexed only by seller and id, and their events not by discrepancy at all, so every read walked the
            -- seller's discrepancies and hashed the resolution events of all sellers.
            CREATE INDEX billium_ledger_discrepancy_observed_idx
                ON public.billium_ledger_discrepancy (seller_ref, environment, observed_payment_id);
            CREATE INDEX billium_discrepancy_event_discrepancy_idx
                ON public.billium_discrepancy_event (discrepancy_id, kind);
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
