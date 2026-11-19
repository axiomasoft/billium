<?php

declare(strict_types=1);

namespace Billium\Execution\Data\Fiscal;

use Billium\Execution\Enums\Fiscal\FiscalDriverOutcomeState;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class FiscalDriverResult
{
    /** @param array<string, mixed> $sanitizedDetails */
    public function __construct(
        public FiscalDriverOutcomeState $state,
        public ?string $providerReceiptId = null,
        public ?string $fiscalDocumentNumber = null,
        public ?string $errorCode = null,
        public array $sanitizedDetails = [],
        public ?DateTimeImmutable $occurredAt = null,
    ) {}
}
