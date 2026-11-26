<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

/** Authorized processing metadata without raw payload, archive locator or transferable worker claim. */
#[Api(since: '1.0')]
final readonly class ExecutionProviderWorkStatus
{
    public function __construct(
        public ExecutionScope $scope,
        public string $sourceAuthority,
        public string $operationRef,
        public string $workRef,
        public string $evidenceRef,
        public string $providerId,
        public string $merchantAccountRef,
        public ?string $providerEventId,
        public string $payloadDigest,
        public string $signatureVerdict,
        public DateTimeImmutable $receivedAt,
        public string $retainedHandlerVersion,
        public string $workHandlerVersion,
        public string $state,
        public int $attemptCount,
        public int $leaseFence,
        public ?DateTimeImmutable $leaseExpiresAt,
        public DateTimeImmutable $dueAt,
        public ?string $lastErrorCode,
        public ?DateTimeImmutable $lastErrorAt,
        public ?string $resultRef,
    ) {
        if ($sourceAuthority === '' || $operationRef === '' || $workRef === '' || $evidenceRef === '' || $providerId === ''
            || $merchantAccountRef === '' || $payloadDigest === '' || $signatureVerdict === '' || $state === ''
            || $retainedHandlerVersion === '' || $workHandlerVersion === '' || $attemptCount < 0 || $leaseFence < 0) {
            throw new InvalidArgumentException('Incomplete retained provider work metadata');
        }
    }
}
