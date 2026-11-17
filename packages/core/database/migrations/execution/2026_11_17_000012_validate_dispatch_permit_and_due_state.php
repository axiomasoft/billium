<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.billium_execution_attempt VALIDATE CONSTRAINT ex_collection_dispatch_permit_length;
            ALTER TABLE public.billium_execution_dispatch_work VALIDATE CONSTRAINT ex_dispatch_due_state;
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
