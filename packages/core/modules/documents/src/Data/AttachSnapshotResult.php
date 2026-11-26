<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class AttachSnapshotResult
{
    public function __construct(
        public bool $attached,
        public bool $staleIgnored,
        public DocumentFinalizationView $finalization,
    ) {}
}
