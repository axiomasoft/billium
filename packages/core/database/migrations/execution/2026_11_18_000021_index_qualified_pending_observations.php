<?php

declare(strict_types=1);

use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX billium_execution_qualified_observation_identity ON public.billium_execution_provider_fact(provider_identity_scope,fact_identity)
                WHERE normalized_snapshot->>'bindingDigest' IS NOT NULL AND (normalized_snapshot->>'operationAmount' IS NOT NULL OR
                    (normalized_snapshot->>'providerReference' IS NOT NULL AND normalized_snapshot->>'normalizedStatus' IN ('pending','unknown')));
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
