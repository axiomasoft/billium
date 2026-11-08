<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Shared backstop of immutable platform history (outbox events, commit log, transitions): no UPDATE/DELETE.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.billium_append_only() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                RAISE EXCEPTION 'billium: % is append-only (% refused)', TG_TABLE_NAME, TG_OP USING ERRCODE = '55000';
            END $$;
            REVOKE ALL ON FUNCTION public.billium_append_only() FROM PUBLIC;
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
