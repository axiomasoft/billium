<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Refund detail replay (billium_record_refund_detail), refund reconciliation, payment status and checkpoint
            -- reads find a receipt by its semantic digest. Without an index each lookup walked every receipt of the seller.
            CREATE INDEX billium_allocation_receipt_digest_idx
                ON public.billium_allocation_receipt (seller_ref, environment, semantic_digest);
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
