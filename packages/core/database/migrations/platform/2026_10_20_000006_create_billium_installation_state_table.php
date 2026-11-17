<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        // Diagnostic/adopted metadata, never a standalone fence (D54/D62); rank 20 only for explicit admin transitions.
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_installation_state (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
                incarnation bigint NOT NULL DEFAULT 1 CHECK (incarnation >= 1),
                routing_epoch bigint NOT NULL DEFAULT 0 CHECK (routing_epoch >= 0),
                dispatch_enabled boolean NOT NULL DEFAULT false,
                writer_checkpoint text NULL CHECK (writer_checkpoint IS NULL OR length(writer_checkpoint) <= 200),
                lease_epoch bigint NOT NULL DEFAULT 0 CHECK (lease_epoch >= 0),
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT billium_installation_state_installation_key UNIQUE (installation_ref)
            );
            SQL);
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_installation_state');
    }

    public function down(): void
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
