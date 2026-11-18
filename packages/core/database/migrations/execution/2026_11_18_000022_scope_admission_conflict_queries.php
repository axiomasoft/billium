<?php

declare(strict_types=1);

use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.billium_execution_admission_conflict ADD COLUMN source_authority text;
            ALTER TABLE public.billium_execution_admission_conflict ADD CONSTRAINT billium_execution_conflict_source
                CHECK (source_authority IS NULL OR length(source_authority) BETWEEN 1 AND 200) NOT VALID;
            CREATE INDEX billium_execution_admission_conflict_history
                ON public.billium_execution_admission_conflict
                    (installation_ref,seller_ref,environment,intent_owner_namespace,source_authority,
                     command_kind,command_key_digest,recorded_at DESC,audit_ref DESC)
                WHERE source_authority IS NOT NULL;
            CREATE INDEX billium_execution_revision_reduction
                ON public.billium_execution_operation_revision
                    (installation_ref,seller_ref,environment,operation_ref,receipt_ref,revision DESC);
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
