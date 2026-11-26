<?php

declare(strict_types=1);

namespace Billium\Server\Contracts;

use Billium\Server\Data\SellerAdmission;
use Billium\Server\Data\SellerScope;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Host-owned admission state for this exact seller and environment. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface SellerEligibility
{
    public function admission(SellerScope $scope): ?SellerAdmission;
}
