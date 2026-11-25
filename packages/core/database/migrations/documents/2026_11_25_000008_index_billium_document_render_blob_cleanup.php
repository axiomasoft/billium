<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- cleanupOrphanBlobs reads the seller's orphaned and half-deleted blobs in id order. Attached and deleted blobs
            -- are retained, so without this partial index every sweep walked all render blobs of the seller.
            CREATE INDEX billium_document_render_blob_cleanup_idx
                ON public.billium_document_render_blob (seller_ref, environment, id)
                WHERE status IN ('orphaned', 'deleting');
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
