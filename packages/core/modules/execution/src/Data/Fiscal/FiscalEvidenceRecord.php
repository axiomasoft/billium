<?php

declare(strict_types=1);

namespace Billium\Execution\Data\Fiscal;

use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class FiscalEvidenceRecord
{
    /** @param array<string, mixed> $sanitizedPayload */
    public function __construct(
        public int $id,
        public string $evidenceRef,
        public string $fiscalIntentRef,
        public string $evidenceKind,
        public string $driverId,
        public string $driverContractVersion,
        public string $merchantRevisionRef,
        public ?string $credentialVersionRef,
        public string $trustVerdict,
        public ?string $externalEventId,
        public string $outcomeState,
        public string $payloadDigest,
        public array $sanitizedPayload,
        public DateTimeImmutable $recordedAt,
    ) {}
}
