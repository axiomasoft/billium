<?php

declare(strict_types=1);

namespace Billium\Testkit\Restore;

use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\DispatchDecision;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\OperationRecord;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DispatchManifest
{
    public function __construct(public OperationRecord $op, public AttemptRecord $attempt, public MerchantRevision $merchant,
        public string $lineage, public DispatchDecision $permit, public string $namespace, public ?string $providerOperationId) {}
}
