<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class DunningCasePolicyMigrationView
{
    public function __construct(
        public int $id,
        public string $sellerRef,
        public string $environment,
        public string $migrationRef,
        public int $dunningCaseId,
        public string $caseRef,
        public string $fromPolicyRef,
        public int $fromPolicyVersion,
        public string $toPolicyRef,
        public int $toPolicyVersion,
        public int $fromCaseRevision,
        public int $toCaseRevision,
        public string $reason,
        public string $actorRef,
        public string $migrationDigest,
        public DateTimeImmutable $migratedAt,
    ) {}
}
