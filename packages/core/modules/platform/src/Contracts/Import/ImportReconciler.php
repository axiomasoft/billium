<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Import;

use Billium\Platform\Data\Import\ImportReconciliationReport;
use Packman\Attributes\Api;

/**
 * Strictly read-only per-(sellerRef, currency, recordKind) import batch reconciler producing a canonical hashed report.
 */
#[Api(since: '1.0')]
interface ImportReconciler
{
    public function reconcileBatch(string $batchId, string $migrationSourceAuthority = 'standalone-billium'): ImportReconciliationReport;
}
