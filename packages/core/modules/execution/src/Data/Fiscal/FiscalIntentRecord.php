<?php

declare(strict_types=1);

namespace Billium\Execution\Data\Fiscal;

use Billium\Contracts\Data\Money;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Enums\Fiscal\FiscalCallbackTrustStrategy;
use Billium\Execution\Enums\Fiscal\FiscalIntentState;
use Billium\Execution\Enums\Fiscal\FiscalReceiptKind;
use Billium\Execution\Enums\Fiscal\FiscalRoute;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class FiscalIntentRecord
{
    /**
     * @param  array<string, mixed>  $exposureProvenance
     * @param  array<string, mixed>  $payloadSnapshot
     */
    public function __construct(
        public int $id,
        public string $intentRef,
        public ExecutionScope $scope,
        public string $executionProfile,
        public string $economicEffectRef,
        public string $rootOperationRef,
        public string $operationRef,
        public FiscalReceiptKind $receiptKind,
        public int $correctionRevision,
        public ?string $parentFiscalIntentRef,
        public string $externalId,
        public FiscalRoute $fiscalRoute,
        public string $driverId,
        public string $driverContractVersion,
        public FiscalCallbackTrustStrategy $callbackTrustStrategy,
        public string $merchantRevisionRef,
        public string $merchantAccountRef,
        public string $providerEvidenceRef,
        public int $policyVersion,
        public string $policyDigest,
        public Money $amount,
        public bool $exceptional,
        public array $exposureProvenance,
        public array $payloadSnapshot,
        public string $payloadDigest,
        public FiscalIntentState $state,
        public ?string $providerReceiptId,
        public ?string $fiscalDocumentNumber,
        public ?string $lastErrorCode,
        public int $lookupAttempts,
        public ?DateTimeImmutable $nextLookupAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
