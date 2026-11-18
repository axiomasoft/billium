<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Applying a bootstrap takes the session's staged items one by one in manifest order. The session key
            -- index also holds every applied item, so each step re-read and sorted the whole session.
            CREATE INDEX billium_feed_bootstrap_item_staged
                ON public.billium_feed_bootstrap_item (session_id,id) WHERE state = 'staged';
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
