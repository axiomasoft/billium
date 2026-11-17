<?php

declare(strict_types=1);

namespace Billium\Core\Contracts\Runtime;

use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use stdClass;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface AuthenticatedObservationContext
{
    /**
     * Network-free check of an independently authenticated command callback before taking raw custody.
     *
     * @return array{commandRef:string,bindingDigest:string}
     */
    public function assertOrigin(Caller $caller, FinancialScope $scope, stdClass $payload, string $proofDigest): array;
}
