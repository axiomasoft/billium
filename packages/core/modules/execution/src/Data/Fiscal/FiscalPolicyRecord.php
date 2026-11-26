<?php

declare(strict_types=1);

namespace Billium\Execution\Data\Fiscal;

use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Enums\Fiscal\FiscalCallbackTrustStrategy;
use Billium\Execution\Enums\Fiscal\FiscalOperationKind;
use Billium\Execution\Enums\Fiscal\FiscalRoute;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class FiscalPolicyRecord
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public int $id,
        public ExecutionScope $scope,
        public string $executionProfile,
        public FiscalOperationKind $operationKind,
        public int $policyVersion,
        public bool $receiptRequired,
        public FiscalRoute $fiscalRoute,
        public ?string $driverId,
        public ?string $driverContractVersion,
        public ?FiscalCallbackTrustStrategy $callbackTrustStrategy,
        public string $policyDigest,
        public array $metadata,
        public DateTimeImmutable $createdAt,
    ) {}
}
