<?php

declare(strict_types=1);

use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.billium_execution_merchant_revision ADD COLUMN settlement_account_ref text;
            ALTER TABLE public.billium_execution_merchant_revision ADD COLUMN mandate_namespace_ref text;
            ALTER TABLE public.billium_execution_merchant_revision ADD CONSTRAINT ex_merchant_settlement_namespace CHECK
                (settlement_account_ref IS NOT NULL AND length(settlement_account_ref) BETWEEN 1 AND 200
                AND mandate_namespace_ref IS NOT NULL AND length(mandate_namespace_ref) BETWEEN 1 AND 200) NOT VALID;
            CREATE UNIQUE INDEX ex_merchant_seller_financial_namespaces ON public.billium_execution_merchant_revision
                (installation_ref,seller_ref,environment,revision_ref,merchant_account_ref,settlement_account_ref,mandate_namespace_ref);
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
