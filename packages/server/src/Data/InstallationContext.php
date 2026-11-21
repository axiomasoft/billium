<?php

declare(strict_types=1);

namespace Billium\Server\Data;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class InstallationContext
{
    public function __construct(public string $installationRef, public string $environment)
    {
        if (trim($installationRef) === '' || trim($environment) === '') {
            throw new InvalidArgumentException('Installation and environment are required');
        }
    }
}
