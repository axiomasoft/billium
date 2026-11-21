<?php

declare(strict_types=1);

namespace Billium\Server\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ResourceScope
{
    /** @param array<string, mixed> $selectors Untrusted resource selectors from the validated envelope. */
    public function __construct(
        public string $installationRef,
        public string $environment,
        public string $sellerRef,
        public array $selectors,
    ) {}
}
