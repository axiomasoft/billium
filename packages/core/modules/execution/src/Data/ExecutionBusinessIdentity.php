<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Data\Money;
use Billium\Execution\Enums\EffectKind;
use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ExecutionBusinessIdentity
{
    public function __construct(
        public FrozenBinding $binding,
        public EffectKind $kind,
        public Money $requested,
        public string $state,
        public int $revision,
        public string $economicDigest,
        public ?string $dispatchRequestDigest,
        public DateTimeImmutable $firstSeenAt,
        public string $retentionPolicy,
        public ?DateTimeImmutable $retentionDeadlineAt,
        public string $receiptRef,
    ) {
        if ($state === '' || $revision < 0 || $receiptRef === ''
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $economicDigest) !== 1
            || ($dispatchRequestDigest !== null && preg_match('/^sha256:[a-f0-9]{64}$/D', $dispatchRequestDigest) !== 1)
            || $retentionPolicy !== 'execution-business-identity-indefinite-v1' || $retentionDeadlineAt !== null) {
            throw new InvalidArgumentException('Invalid retained execution business identity');
        }
    }
}
