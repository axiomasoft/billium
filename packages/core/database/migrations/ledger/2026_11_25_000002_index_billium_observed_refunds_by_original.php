<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Payment views, refund reconciliation and import look up the refunds of one original charge. No index led
            -- with original_ref, so each lookup scanned every observation of the seller's source authority.
            CREATE INDEX billium_observed_payment_refund_original_idx
                ON public.billium_observed_payment (seller_ref, environment, source_authority, original_ref)
                WHERE original_ref IS NOT NULL;
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
