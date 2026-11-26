<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts\Fiscal;

use Billium\Execution\Data\Fiscal\FiscalCallbackEnvelope;
use Billium\Execution\Data\Fiscal\FiscalCallbackVerification;
use Billium\Execution\Data\Fiscal\FiscalDriverCapabilities;
use Billium\Execution\Data\Fiscal\FiscalDriverResult;
use Billium\Execution\Data\Fiscal\FiscalIntentRecord;
use Billium\Execution\Data\Fiscal\FiscalLookupRequest;
use Billium\Execution\Data\Fiscal\FiscalRegisterRequest;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Fiscal provider / PSP receipt passthrough SPI. Network methods require transaction level zero; verifyCallback is pure.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface FiscalDriver
{
    public function driverId(): string;

    public function contractVersion(): string;

    public function capabilities(): FiscalDriverCapabilities;

    public function registerReceipt(FiscalRegisterRequest $request): FiscalDriverResult;

    public function findReceipt(FiscalLookupRequest $request): FiscalDriverResult;

    public function verifyCallback(FiscalCallbackEnvelope $envelope, FiscalIntentRecord $intent): FiscalCallbackVerification;
}
