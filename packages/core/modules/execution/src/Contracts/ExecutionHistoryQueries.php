<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Execution\Data\AdmissionConflictAudit;
use Billium\Execution\Data\ChildIntentIdentity;
use Billium\Execution\Data\ExecutionBusinessIdentity;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Enums\EffectKind;
use Billium\Platform\Data\Idempotency\StoredCommandReceipt;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Additional authorized PHP reads; the existing wire schemas retain their original shape. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface ExecutionHistoryQueries
{
    public function business(ExecutionScope $scope, string $sourceAuthority, string $sourceContext, string $businessIntentRef): ExecutionBusinessIdentity;

    public function childBusiness(ExecutionScope $scope, string $sourceAuthority, EffectKind $kind, string $sourceContext, string $businessIntentRef): ChildIntentIdentity;

    public function receiptByCommandKey(ExecutionScope $scope, string $sourceAuthority, string $schema, string $sourceContext, string $commandKey): StoredCommandReceipt;

    /** @return list<AdmissionConflictAudit> */
    public function admissionConflicts(ExecutionScope $scope, string $sourceAuthority, string $schema, string $sourceContext, string $commandKey, int $limit = 25): array;
}
