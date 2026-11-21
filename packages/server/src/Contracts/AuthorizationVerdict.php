<?php

declare(strict_types=1);

namespace Billium\Server\Contracts;

use Billium\Server\Data\AuthenticatedPrincipal;
use Billium\Server\Data\ResourceScope;
use Billium\Server\Enums\Verdict;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface AuthorizationVerdict
{
    public function authorize(AuthenticatedPrincipal $principal, string $action, ResourceScope $resourceScope, string $payloadDigest): Verdict;
}
