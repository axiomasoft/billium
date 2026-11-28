<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Reducers;

use Billium\Collection\Enums\ConsentGuardStatus;
use DateTimeImmutable;
use Packman\Attributes\Internal;

#[Internal]
final readonly class ConsentRevocationDecision
{
    public function __construct(
        public int $newGuardRevision,
        public ConsentGuardStatus $targetStatus,
        public DateTimeImmutable $effectiveAt,
        public DateTimeImmutable $revokedAt,
        public string $revocationReceiptRef,
        public string $revocationReason,
    ) {}
}
