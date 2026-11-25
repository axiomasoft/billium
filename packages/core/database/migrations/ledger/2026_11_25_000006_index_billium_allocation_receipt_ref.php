<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- reverseAllocation addresses the original allocation by its public reference (receipt_ref) and reads it twice,
            -- before and after taking its lock plan. Without an index each reversal walked every allocation of the seller.
            CREATE INDEX billium_allocation_receipt_ref_idx
                ON public.billium_allocation (seller_ref, environment, receipt_ref);
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
