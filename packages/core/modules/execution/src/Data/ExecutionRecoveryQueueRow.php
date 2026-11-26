<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ExecutionRecoveryQueueRow
{
    public function __construct(
        public string $operationRef,
        public string $rootOperationRef,
        public string $attemptRef,
        public string $state,
        public string $namespaceState,
        public string $ownerNamespace,
        public string $merchantAccountRef,
        public string $merchantRevisionRef,
        public string $providerId,
        public string $bindingDigest,
        public string $currency,
        public DateTimeImmutable $nextReconcileAt,
        public ?DateTimeImmutable $lastReconciledAt,
        public int $dispatchWorkAttempts,
        public int $ageSeconds,
        public ?string $lastErrorCode,
        public ?DateTimeImmutable $lastErrorAt,
        public ?string $evidenceRef,
    ) {}
}
