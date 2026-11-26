<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- A document correction sums the prior credit note lines of its original invoice before it caps the new credit.
            -- Without an index that read walked every credit note line of the seller inside the correction transaction.
            CREATE INDEX billium_document_credit_note_line_original_idx
                ON public.billium_document_credit_note_line (seller_ref, environment, original_legal_document_id);
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
