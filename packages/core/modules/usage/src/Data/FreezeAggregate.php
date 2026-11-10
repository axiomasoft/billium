<?php

declare(strict_types=1);

namespace Billium\Usage\Data;

final readonly class FreezeAggregate
{
    public function __construct(
        public WindowSelector $window,
        public ?string $expectedHeadDigest = null,
        public ?string $commandKey = null,
    ) {}
}
