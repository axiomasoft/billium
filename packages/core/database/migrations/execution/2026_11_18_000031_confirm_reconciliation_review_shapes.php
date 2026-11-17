<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.billium_execution_recon_review VALIDATE CONSTRAINT ex_recon_expected_status;
            ALTER TABLE public.billium_execution_recon_review VALIDATE CONSTRAINT ex_recon_observed_identity;
            ALTER TABLE public.billium_execution_recon_item VALIDATE CONSTRAINT ex_recon_item_basis_status;
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
