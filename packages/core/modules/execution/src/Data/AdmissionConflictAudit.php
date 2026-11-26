<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class AdmissionConflictAudit
{
    public function __construct(
        public string $auditRef,
        public ProblemCode $problem,
        public string $actorRef,
        public DateTimeImmutable $recordedAt,
        public string $commandKeyDigest,
        public string $semanticDigest,
        public ?string $originalOperationRef,
    ) {
        if ($auditRef === '' || $actorRef === '' || $originalOperationRef === '' || $problem !== ProblemCode::IdempotencyConflict
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $commandKeyDigest) !== 1
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $semanticDigest) !== 1) {
            throw new InvalidArgumentException('Invalid immutable admission conflict audit');
        }
    }
}
