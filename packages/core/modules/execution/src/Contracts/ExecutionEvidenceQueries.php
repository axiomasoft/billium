<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Execution\Data\ExecutionProviderWorkStatus;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\MaterialSnapshot;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface ExecutionEvidenceQueries
{
    public function material(ExecutionScope $scope, string $sourceAuthority, string $operationRef, ?int $revision = null): ?MaterialSnapshot;

    public function work(ExecutionScope $scope, string $sourceAuthority, string $operationRef, string $workRef): ?ExecutionProviderWorkStatus;
}
