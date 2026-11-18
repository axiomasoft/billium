<?php

declare(strict_types=1);

use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.billium_execution_recon_review ADD CONSTRAINT ex_recon_expected_status
                CHECK ((jsonb_typeof(expected->'status')='string' AND length(expected->>'status') BETWEEN 1 AND 200) IS TRUE) NOT VALID;
            ALTER TABLE public.billium_execution_recon_review ADD CONSTRAINT ex_recon_observed_identity
                CHECK (((jsonb_typeof(observed->'factRef')='string' AND length(observed->>'factRef') BETWEEN 1 AND 200)
                    OR (jsonb_typeof(observed->'lookupState')='string' AND length(observed->>'lookupState') BETWEEN 1 AND 200)) IS TRUE) NOT VALID;
            ALTER TABLE public.billium_execution_recon_item ADD CONSTRAINT ex_recon_item_basis_status
                CHECK ((jsonb_typeof(comparison_basis->'status')='string' AND length(comparison_basis->>'status') BETWEEN 1 AND 200) IS TRUE) NOT VALID;
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
