<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            SET LOCAL lock_timeout = '2s';
            ALTER TABLE public.billium_usage_aggregate_snapshot
                ADD COLUMN IF NOT EXISTS metric_unit text NULL;
            ALTER TABLE public.billium_usage_aggregate_snapshot
                ADD CONSTRAINT billium_usage_aggregate_snapshot_metric_unit_nonempty
                CHECK (metric_unit IS NULL OR metric_unit <> '') NOT VALID;
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
