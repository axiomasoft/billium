<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Execution\Data\Ambiguous;
use Billium\Execution\Data\CallbackInput;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\CertifiedAbsent;
use Billium\Execution\Data\DriverCommand;
use Billium\Execution\Data\DriverLookup;
use Billium\Execution\Data\DriverPage;
use Billium\Execution\Data\ExecutionBinding;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\RejectedCallback;
use Billium\Execution\Data\Unavailable;
use Billium\Execution\Data\UnsupportedCapability;
use Billium\Execution\Data\VerifiedCallback;

/**
 * Neutral provider bridge. No SDK, persistence or retry ownership (D26/D57).
 * Mutations are invoked only after capability/WriterAdmission checks and outside DB transactions.
 * Lookup/callback/inventory/report methods are read-only; unavailable/ambiguous never mean absent.
 */
#[Api]
#[Spi(SpiKind::Implement)]
interface PaymentDrivers
{
    public function capabilities(ExecutionBinding $binding): CapabilitySnapshot;

    public function initiate(DriverCommand $command): ProviderEvidence|Unavailable|Ambiguous|UnsupportedCapability;

    public function find(DriverLookup $query): ProviderEvidence|CertifiedAbsent|Unavailable|Ambiguous|UnsupportedCapability;

    public function capture(DriverCommand $command): ProviderEvidence|Unavailable|Ambiguous|UnsupportedCapability;

    public function void(DriverCommand $command): ProviderEvidence|Unavailable|Ambiguous|UnsupportedCapability;

    public function refund(DriverCommand $command): ProviderEvidence|Unavailable|Ambiguous|UnsupportedCapability;

    public function findRefund(DriverLookup $query): ProviderEvidence|CertifiedAbsent|Unavailable|Ambiguous|UnsupportedCapability;

    public function verifyCallback(CallbackInput $callback): VerifiedCallback|RejectedCallback|Unavailable|Ambiguous|UnsupportedCapability;

    public function inventory(ExecutionBinding $binding, ?string $cursor = null): DriverPage|Unavailable|Ambiguous|UnsupportedCapability;

    public function settlement(ExecutionBinding $binding, ?string $cursor = null): DriverPage|Unavailable|Ambiguous|UnsupportedCapability;

    public function disputes(ExecutionBinding $binding, ?string $cursor = null): DriverPage|Unavailable|Ambiguous|UnsupportedCapability;
}
