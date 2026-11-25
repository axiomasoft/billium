<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Manual repair (late refund adjudication, linked correction approval) finds the inventory item of a review
            -- inside its financial transaction. Items are retained from every reconciliation run and only discrepancies
            -- carry a review, so a partial index stays small while the lookup no longer scans all retained items.
            CREATE INDEX billium_execution_recon_item_review_idx
                ON public.billium_execution_recon_item (review_ref)
                WHERE review_ref IS NOT NULL;
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
