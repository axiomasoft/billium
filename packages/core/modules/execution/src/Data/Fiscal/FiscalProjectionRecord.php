<?php

declare(strict_types=1);

namespace Billium\Execution\Data\Fiscal;

use Billium\Contracts\Data\Money;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Enums\Fiscal\FiscalReceiptKind;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class FiscalProjectionRecord
{
    /**
     * @param  array<string, mixed>  $exposureProvenance
     * @param  array<string, mixed>  $sourcePayload
     */
    public function __construct(
        public int $id,
        public string $projectionRef,
        public ExecutionScope $scope,
        public string $executionProfile,
        public string $sourceAuthority,
        public string $economicEffectRef,
        public string $rootOperationRef,
        public string $operationRef,
        public FiscalReceiptKind $receiptKind,
        public int $correctionRevision,
        public ?string $externalFiscalRef,
        public string $fiscalStatus,
        public Money $amount,
        public bool $exceptional,
        public array $exposureProvenance,
        public array $sourcePayload,
        public string $sourceDigest,
        public DateTimeImmutable $recordedAt,
    ) {}
}
