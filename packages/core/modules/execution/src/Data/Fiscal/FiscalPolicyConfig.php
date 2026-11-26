<?php

declare(strict_types=1);

namespace Billium\Execution\Data\Fiscal;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Enums\Fiscal\FiscalCallbackTrustStrategy;
use Billium\Execution\Enums\Fiscal\FiscalOperationKind;
use Billium\Execution\Enums\Fiscal\FiscalRoute;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class FiscalPolicyConfig
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public ExecutionScope $scope,
        public string $executionProfile,
        public FiscalOperationKind $operationKind,
        public bool $receiptRequired,
        public FiscalRoute $fiscalRoute,
        public ?string $driverId = null,
        public ?string $driverContractVersion = null,
        public ?FiscalCallbackTrustStrategy $callbackTrustStrategy = null,
        public array $metadata = [],
    ) {
        if (! in_array($executionProfile, ['standalone-billium', 'remote-billium', 'embedded-commerz'], true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Unsupported execution profile for fiscal policy');
        }
        if ($executionProfile === 'embedded-commerz' && ! in_array($fiscalRoute, [FiscalRoute::ProjectionOnly, FiscalRoute::None], true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'embedded-commerz may only configure projection_only or none fiscal route');
        }
        if ($executionProfile !== 'embedded-commerz' && $fiscalRoute === FiscalRoute::ProjectionOnly) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Execution-owning profiles may only configure psp_passthrough, fiscal_driver, or none');
        }
        if ($receiptRequired && $fiscalRoute === FiscalRoute::None) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'receiptRequired requires a non-none fiscal route');
        }
        if ($fiscalRoute->isDispatchable() && ($driverId === null || $driverId === '' || $driverContractVersion === null || $driverContractVersion === '')) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Dispatchable fiscal routes require driverId and driverContractVersion');
        }
        if (! $fiscalRoute->isDispatchable() && ($driverId !== null || $driverContractVersion !== null)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Non-dispatchable fiscal routes must not specify a driver');
        }
    }
}
