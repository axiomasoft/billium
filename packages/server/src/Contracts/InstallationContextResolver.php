<?php

declare(strict_types=1);

namespace Billium\Server\Contracts;

use Billium\Server\Data\AuthenticatedPrincipal;
use Billium\Server\Data\InstallationContext;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface InstallationContextResolver
{
    /** Resolve installation/environment for capabilities from current authenticated host policy. */
    public function resolve(AuthenticatedPrincipal $principal): ?InstallationContext;
}
