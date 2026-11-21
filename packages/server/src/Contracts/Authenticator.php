<?php

declare(strict_types=1);

namespace Billium\Server\Contracts;

use Billium\Server\Data\AuthenticatedPrincipal;
use Billium\Server\Data\RequestMeta;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface Authenticator
{
    /**
     * Reject absent or invalid credentials with billium.auth.denied.
     *
     * @param  array<string, list<string|null>>  $headers
     */
    public function authenticate(array $headers, RequestMeta $requestMeta): AuthenticatedPrincipal;
}
