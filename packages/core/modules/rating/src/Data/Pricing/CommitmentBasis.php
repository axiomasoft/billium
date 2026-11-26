<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Pricing;

use Packman\Attributes\Api;

#[Api]
final readonly class CommitmentBasis
{
    public function __construct(
        public string $alreadyBilledNetMinor,
        public string $basisDigest,
    ) {}
}
