<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Custody;

use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ProviderEvidence;
use Packman\Attributes\Internal;

#[Internal]
final readonly class VerifiedDelivery
{
    public function __construct(public ProviderEvidence $evidence, public MerchantRevision $merchant, public CapabilitySnapshot $capability,
        public MaterialSnapshot $snapshot, public ?OperationRecord $operation, public ?AttemptRecord $attempt) {}
}
