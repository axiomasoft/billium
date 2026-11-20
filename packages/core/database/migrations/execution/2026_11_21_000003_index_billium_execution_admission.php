<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE INDEX billium_execution_operation_root_idx ON public.billium_execution_operation (installation_ref, seller_ref, environment, intent_owner_namespace, root_operation_ref, id);
            CREATE INDEX billium_execution_reservation_root_idx ON public.billium_execution_reservation (installation_ref, seller_ref, environment, root_operation_ref, id);
            CREATE INDEX billium_execution_partition_root_idx ON public.billium_execution_observation_partition (installation_ref, seller_ref, environment, root_operation_ref, id);
            CREATE INDEX billium_execution_binding_intent_idx ON public.billium_execution_business_binding (installation_ref, seller_ref, environment, business_intent_ref);
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
