<?php

declare(strict_types=1);

namespace Billium\Usage\Data\Commands;

use Billium\Usage\Data\SourceCompleteness;
use Packman\Attributes\Api;

#[Api]
final readonly class RecordSourceCompleteness
{
    public function __construct(
        public string $sellerRef,
        public string $environment,
        public SourceCompleteness $evidence,
    ) {}
}
