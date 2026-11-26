<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE INDEX billium_obligation_account_due_idx ON public.billium_obligation (installation_ref, seller_ref, environment, billing_account_ref, status, due_at, id) WHERE status = 'open' AND due_at IS NOT NULL;
            CREATE INDEX billium_payment_ref_idx ON public.billium_payment (installation_ref, seller_ref, environment, payment_ref);
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
