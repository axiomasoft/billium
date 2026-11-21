<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Admission;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Contracts\AdapterQualification;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\PaymentDriver;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Internal\Dispatch\TransportBoundary;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Closure;
use Packman\Attributes\Internal;

#[Internal]
final readonly class QualifiedAdapter
{
    /**
     * @param  Closure():PaymentDrivers  $drivers
     * @param  Closure():AdapterQualification  $qualification
     */
    public function __construct(private MerchantRegistry $merchants, private Closure $drivers, private Closure $qualification, private TransportBoundary $boundary) {}

    /** @return array{PaymentDriver,CapabilitySnapshot} */
    public function select(MerchantRevision $merchant, EffectKind $kind, string $currency): array
    {
        $this->boundary->assertOutsideTransaction();
        $capability = $this->merchants->capabilities($merchant->capabilitySnapshotRef);
        $drivers = ($this->drivers)();
        $driver = $drivers->resolve($merchant->driverId, $merchant->driverContractVersion);
        if ($driver->contractVersion() !== $merchant->driverContractVersion || $capability->digest !== $merchant->capabilityDigest
            || $capability->driverContractVersion !== $merchant->driverContractVersion || $capability->driverId !== $merchant->driverId
            || $capability->merchantAccountRef !== $merchant->merchantAccountRef || $capability->snapshotRef !== $merchant->capabilitySnapshotRef
            || ! in_array($currency, $merchant->currencyCodes, true) || ExecutionCodec::differs($drivers->capabilities($merchant), $capability)) {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'No exact registered driver and immutable account capability match');
        }
        $capability->requireEffect($kind, $currency, $merchant->scope->environment === 'production');
        if (in_array($kind, [EffectKind::Charge, EffectKind::Authorize], true) && ! $capability->details->initiateWithIdempotency && ! $capability->details->initiateWithoutIdempotency) {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'No qualified initiation mode');
        }
        ($this->qualification)()->verify($merchant, $capability, $kind);

        return [$driver, $capability];
    }
}
