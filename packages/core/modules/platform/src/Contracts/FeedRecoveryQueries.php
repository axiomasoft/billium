<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;

/** D45/D60: platform-owned recovery metadata, read in the caller's consistent read-only snapshot (REQ-CON-14). */
#[Api]
#[Spi(SpiKind::Call)]
interface FeedRecoveryQueries
{
    /**
     * Scope comes from CallerContext. The supplied named connection must already hold a REPEATABLE READ READ ONLY
     * root; this read never opens another transaction, changes a partition or invokes an observation handler.
     *
     * @return array{recoveryRequired: bool, bootstrapStatus: string, snapshots: list<array<string, mixed>>}
     */
    public function state(string $connectionName, string $channel, ?string $sourceAuthority = null, ?string $partitionRef = null): array;

    /** Pending park reason in the caller's REPEATABLE READ READ ONLY root snapshot, if any. */
    public function pendingOperationPark(string $connectionName, string $channel, string $sourceAuthority, string $operationRef): ?string;
}
