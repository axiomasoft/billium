<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\RecurringOwnerMode;
use Billium\Collection\Enums\RecurringOwnerStatus;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class RecurringOwnerView
{
    public function __construct(
        public int $id,
        public string $sellerRef,
        public string $environment,
        public string $agreementRef,
        public string $componentRef,
        public RecurringOwnerMode $ownerMode,
        public string $ownerRef,
        public int $ownerRevision,
        public RecurringOwnerStatus $status,
        public ?string $retiredReason,
        public string $configDigest,
        public DateTimeImmutable $recordedAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
