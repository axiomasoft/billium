<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.billium_execution_authorization VALIDATE CONSTRAINT ex_authorization_release_basis;
            ALTER TABLE public.billium_execution_authorization VALIDATE CONSTRAINT ex_authorization_expiry_pair;
            ALTER TABLE public.billium_execution_authorization VALIDATE CONSTRAINT ex_authorization_release_reference;
            ALTER TABLE public.billium_execution_admission_conflict VALIDATE CONSTRAINT ex_execution_conflict_command_kind;
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
