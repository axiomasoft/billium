<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.billium_execution_business_binding VALIDATE CONSTRAINT ex_business_source_context;
            ALTER TABLE public.billium_execution_operation VALIDATE CONSTRAINT ex_operation_source_context;
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
