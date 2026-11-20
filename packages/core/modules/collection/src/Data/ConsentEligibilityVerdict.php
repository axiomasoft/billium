<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\ConsentGuardStatus;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ConsentEligibilityVerdict
{
    public function __construct(
        public bool $permitted,
        public ?string $denialCode,
        public ?int $bindingId,
        public ?int $consentGuardId,
        public ?int $guardRevision,
        public ?int $sourceRevision,
        public ?int $cancellationGeneration,
        public ?ConsentGuardStatus $guardStatus,
        public ?string $proofDigest,
    ) {}
}
