<?php

declare(strict_types=1);

namespace Billium\Server\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class AuthenticatedPrincipal
{
    public function __construct(public string $principalRef) {}
}
