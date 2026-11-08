<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // D54: append-only diagnostic history; max(commit_no) is never a freshness or activation proof.
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_money_commit_log (
                commit_no bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                kind text NOT NULL CHECK (kind ~ '^[a-z][a-z0-9_.]*$' AND length(kind) <= 200),
                session_epoch bigint NOT NULL CHECK (session_epoch >= 1),
                occurred_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE TRIGGER billium_money_commit_log_append_only BEFORE UPDATE OR DELETE ON public.billium_money_commit_log
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            SQL);
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_money_commit_log');
    }

    public function down(): void
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
