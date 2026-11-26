<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\DriverRequest;
use Billium\Execution\Data\InventoryPage;
use Billium\Execution\Data\LookupRequest;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Data\ResolvedCredentials;
use DateTimeImmutable;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * contractVersion(): string; execute(DriverRequest): ProviderLookupResult; authorize(DriverRequest): ProviderLookupResult; capture(DriverRequest): ProviderLookupResult; voidAuthorization(DriverRequest): ProviderLookupResult; refund(DriverRequest): ProviderLookupResult; lookup(LookupRequest): ProviderLookupResult; inventory(MerchantRevision,ResolvedCredentials,DateTimeImmutable $from,?string $cursor): InventoryPage; normalize(ProviderEvidence,CapabilitySnapshot): MaterialSnapshot. All network methods enforce Tx0; normalize pure. Unsupported typed capability refuses before effect.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
/** Network calls require transaction level zero; normalize is pure. Exact version and historical account binding are mandatory. */
interface PaymentDriver
{
    public function contractVersion(): string;

    public function execute(DriverRequest $driverRequest): ProviderLookupResult;

    public function authorize(DriverRequest $driverRequest): ProviderLookupResult;

    public function capture(DriverRequest $driverRequest): ProviderLookupResult;

    public function voidAuthorization(DriverRequest $driverRequest): ProviderLookupResult;

    public function refund(DriverRequest $driverRequest): ProviderLookupResult;

    public function lookup(LookupRequest $lookupRequest): ProviderLookupResult;

    public function inventory(MerchantRevision $merchantRevision, ResolvedCredentials $resolvedCredentials, DateTimeImmutable $from, ?string $cursor): InventoryPage;

    public function normalize(ProviderEvidence $providerEvidence, CapabilitySnapshot $capabilitySnapshot): MaterialSnapshot;
}
