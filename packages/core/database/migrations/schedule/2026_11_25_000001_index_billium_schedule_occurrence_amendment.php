<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- An occurrence read asks whether its applicable revision was confirmed. The append-only amendment log had
            -- only its primary key, so every read scanned the amendments of every seller and occurrence.
            CREATE INDEX billium_schedule_occurrence_amendment_lookup
                ON public.billium_schedule_occurrence_amendment (seller_ref, environment, occurrence_ref, schedule_revision);
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
