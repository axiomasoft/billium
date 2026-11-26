<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ChildIntentIdentity
{
    public function __construct(
        public OperationRecord $operation,
        public string $businessIntentRef,
        public ?string $reasonCode,
        public ?string $category,
        public string $semanticDigest,
        public DateTimeImmutable $firstSeenAt,
        public string $retentionPolicy,
        public ?DateTimeImmutable $retentionDeadlineAt,
        public string $receiptRef,
    ) {
        if ($operation->operationRef === $operation->rootOperationRef || $businessIntentRef === '' || $reasonCode === '' || $category === ''
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $semanticDigest) !== 1 || $receiptRef === ''
            || $retentionPolicy !== 'execution-business-identity-indefinite-v1' || $retentionDeadlineAt !== null) {
            throw new InvalidArgumentException('Invalid immutable child intent identity');
        }
    }
}
