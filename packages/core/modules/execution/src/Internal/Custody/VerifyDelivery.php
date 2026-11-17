<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Custody;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Contracts\AdapterQualification;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Enums\DriverCapability;
use Billium\Execution\Internal\Authority\AuthorityClaims;
use Billium\Execution\Internal\Dispatch\TransportBoundary;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Closure;
use Packman\Attributes\Internal;

#[Internal]
final readonly class VerifyDelivery
{
    /**
     * @param  Closure():PaymentDrivers  $drivers
     * @param  Closure():AdapterQualification  $qualifications
     */
    public function __construct(private ExecutionStore $store, private MerchantRegistry $merchants, private Closure $drivers,
        private Closure $qualifications, private TransportBoundary $boundary, private CallbackFrame $frames) {}

    public function verify(ExecutionScope $scope, string $revisionRef, ?string $operationHint, ProviderEvidence $evidence, bool $callback): VerifiedDelivery
    {
        $this->boundary->assertOutsideTransaction();
        $merchant = $this->store->merchantRevision($scope, $revisionRef);
        $custody = $evidence;
        if (! $callback) {
            $evidence = $this->frames->original($custody);
        }

        if (get_object_vars($evidence->scope) !== get_object_vars($scope) || $evidence->providerId !== $merchant->providerId
            || $evidence->merchantAccountRef !== $merchant->merchantAccountRef) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Delivery differs from the authenticated merchant route');
        }
        $cap = $this->merchants->capabilities($merchant->capabilitySnapshotRef);
        $drivers = ($this->drivers)();
        $driver = $drivers->resolve($merchant->driverId, $merchant->driverContractVersion);
        if ($driver->contractVersion() !== $merchant->driverContractVersion || $cap->driverContractVersion !== $driver->contractVersion()
            || $cap->driverId !== $merchant->driverId || $cap->digest !== $merchant->capabilityDigest || $cap->merchantAccountRef !== $merchant->merchantAccountRef
            || ExecutionCodec::encode($cap) !== ExecutionCodec::encode($drivers->capabilities($merchant))) {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Original evidence handler and capability are unavailable');
        }
        if ($callback) {
            $cap->requireCapability(DriverCapability::WebhookAuthenticity, $scope->environment === 'production');
        }
        $snapshot = $driver->normalize($evidence, $cap);
        ($this->qualifications)()->verify($merchant, $cap, $snapshot->kind);
        if ($snapshot->merchantAccountRef !== $merchant->merchantAccountRef || $snapshot->evidenceRefs !== [$evidence->evidenceRef]
            || ($operationHint !== null && $operationHint !== $snapshot->operationRef)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Signed identity contradicts the delivery route');
        }
        $operation = $this->store->operation($scope, $snapshot->operationRef);
        $attempt = $operation === null ? null : $this->store->attemptForOperation($scope, $operation->operationRef);
        if ($operation !== null && ($attempt === null || $operation->binding->driverId !== $merchant->driverId
            || $operation->binding->driverContractVersion !== $driver->contractVersion() || $operation->binding->capabilityDigest !== $cap->digest
            || $operation->binding->merchantAccountRef !== $snapshot->merchantAccountRef || $operation->kind !== $snapshot->kind
            || $snapshot->bindingDigest !== AuthorityClaims::bindingDigest($operation->binding) || $snapshot->providerReference !== $attempt->providerReference)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Signed evidence does not identify the original immutable attempt');
        }

        if ($evidence !== $custody) {
            $snapshot = new MaterialSnapshot($snapshot->operationRef, $snapshot->kind, $snapshot->merchantAccountRef, $snapshot->currency,
                $snapshot->authorizedTotal, $snapshot->capturedTotal, $snapshot->refundedTotal, $snapshot->voidedTotal, $snapshot->normalizedStatus,
                $snapshot->providerRevision, $snapshot->stableEffectId, $snapshot->materialDigest, [$custody->evidenceRef], $snapshot->observedAt,
                $snapshot->providerOperationId, $snapshot->bindingDigest, $snapshot->providerReference, $snapshot->operationAmount, $snapshot->authorizationExpiresAt);
        }

        return new VerifiedDelivery($custody, $merchant, $cap, $snapshot, $operation, $attempt);
    }
}
