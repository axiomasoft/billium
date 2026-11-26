<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\MerchantRevision;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * forAdmission(ExecutionScope,string $merchantRevisionRef): MerchantRevision; historical(ExecutionScope,string $rootOperationRef): MerchantRevision; capabilities(string $snapshotRef): CapabilitySnapshot
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface MerchantRegistry
{
    public function forAdmission(ExecutionScope $executionScope, string $merchantRevisionRef): MerchantRevision;

    public function historical(ExecutionScope $executionScope, string $rootOperationRef): MerchantRevision;

    public function capabilities(string $snapshotRef): CapabilitySnapshot;
}
