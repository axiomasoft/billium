<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use Billium\Contracts\Data\Money;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Typed participant payload for recording a proven imported obligation or positive linked correction.
 */
#[Api(since: '1.0')]
final readonly class ImportObligationWrite
{
    public function __construct(
        public string $commandKey,
        public string $sellerRef,
        public string $environment,
        public string $billingAccountRef,
        public string $obligationRef,
        public string $basisRef,
        public string $customerRef,
        public string $componentRef,
        public Money $money,
        public string $termsDigest,
        public ?string $priceVersionRef = null,
        public ?string $taxSnapshotRef = null,
        public ?DateTimeImmutable $dueAt = null,
    ) {}
}
