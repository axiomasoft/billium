<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\ConsentPurpose;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class SavedMethodCapabilityRequirement
{
    public function __construct(
        public CollectionScope $scope,
        public string $merchantAccountRef,
        public string $merchantConfigRevision,
        public string $currency,
        public ConsentPurpose $purpose,
        public bool $production,
    ) {}
}
