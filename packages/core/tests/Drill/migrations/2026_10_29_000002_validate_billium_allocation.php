<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("SET lock_timeout = '2s'; ALTER TABLE public.billium_allocation VALIDATE CONSTRAINT billium_drill_note_length; RESET lock_timeout;");
    }

    public function down(): never
    {
        throw new LogicException('Forward-only validation.');
    }
};
