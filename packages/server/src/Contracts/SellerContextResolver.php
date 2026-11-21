<?php

declare(strict_types=1);

namespace Billium\Server\Contracts;

use Billium\Server\Data\AuthenticatedPrincipal;
use Billium\Server\Data\ExecutionContext;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface SellerContextResolver
{
    /** Resolve seller, installation, environment and grants from host policy for this principal. */
    public function resolve(AuthenticatedPrincipal $principal, string $routeSeller): ExecutionContext;
}
