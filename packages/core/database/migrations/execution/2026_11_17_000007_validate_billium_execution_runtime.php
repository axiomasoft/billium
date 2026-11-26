<?php

declare(strict_types=1);

use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.billium_execution_merchant_revision VALIDATE CONSTRAINT ex_merchant_revision_capability_snapshot_fk;
            ALTER TABLE public.billium_execution_operation VALIDATE CONSTRAINT ex_operation_merchant_revision_fk;
            ALTER TABLE public.billium_execution_operation VALIDATE CONSTRAINT ex_operation_operation_fk;
            ALTER TABLE public.billium_execution_capacity VALIDATE CONSTRAINT ex_capacity_operation_fk;
            ALTER TABLE public.billium_execution_reservation VALIDATE CONSTRAINT ex_reservation_operation_fk;
            ALTER TABLE public.billium_execution_attempt VALIDATE CONSTRAINT ex_attempt_operation_fk;
            ALTER TABLE public.billium_execution_provider_fact VALIDATE CONSTRAINT ex_provider_fact_operation_fk;
            ALTER TABLE public.billium_execution_operation_revision VALIDATE CONSTRAINT ex_operation_revision_operation_fk;
            ALTER TABLE public.billium_execution_authorization VALIDATE CONSTRAINT ex_authorization_operation_fk;
            ALTER TABLE public.billium_execution_provider_work VALIDATE CONSTRAINT ex_provider_work_provider_evidence_fk;
            ALTER TABLE public.billium_execution_dispatch_work VALIDATE CONSTRAINT ex_dispatch_work_attempt_fk;
            ALTER TABLE public.billium_execution_merchant_lifecycle_event VALIDATE CONSTRAINT ex_merchant_lifecycle_event_merchant_revision_fk;
            ALTER TABLE public.billium_execution_merchant_credential_revision VALIDATE CONSTRAINT ex_merchant_credential_revision_merchant_revision_fk;
            ALTER TABLE public.billium_execution_merchant_revision VALIDATE CONSTRAINT ex_merchant_continuity_required;
            ALTER TABLE public.billium_execution_provider_evidence VALIDATE CONSTRAINT ex_evidence_secure_custody;
            ALTER TABLE public.billium_execution_capacity VALIDATE CONSTRAINT ex_capacity_unexpected_hold;
            ALTER TABLE public.billium_execution_operation VALIDATE CONSTRAINT ex_operation_currency_scale;
            ALTER TABLE public.billium_execution_merchant_revision VALIDATE CONSTRAINT ex_merchant_settlement_namespace;
            ALTER TABLE public.billium_execution_merchant_revision ALTER COLUMN continuity_evidence_ref SET NOT NULL;
            ALTER TABLE public.billium_execution_merchant_revision ALTER COLUMN settlement_account_ref SET NOT NULL;
            ALTER TABLE public.billium_execution_merchant_revision ALTER COLUMN mandate_namespace_ref SET NOT NULL;
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
