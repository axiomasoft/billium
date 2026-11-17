<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        RuntimeGrants::grant('UPDATE (collection_dispatch_permit_ref)', 'public.billium_execution_attempt');
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};
