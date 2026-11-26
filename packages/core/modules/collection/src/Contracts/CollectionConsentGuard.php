<?php

declare(strict_types=1);

namespace Billium\Collection\Contracts;

use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\HostConsentAuthoritySnapshot;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Host-supplied current consent/cancellation authority port (`CollectionConsentGuard`).
 * Implementations are invoked strictly outside DB transactions; missing authority must fail closed.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface CollectionConsentGuard
{
    public function currentAuthority(
        CollectionScope $scope,
        string $billingAccountRef,
        string $consentRef,
    ): HostConsentAuthoritySnapshot;
}
