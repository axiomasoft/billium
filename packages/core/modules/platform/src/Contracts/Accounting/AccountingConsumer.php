<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Accounting;

use Billium\Platform\Data\Accounting\AccountingAppliedReceipt;
use Billium\Platform\Data\Accounting\AccountingDeliveryAck;
use Billium\Platform\Data\Accounting\FinBridgeExportEnvelope;
use Billium\Platform\Data\Accounting\QuarantinedFactRecord;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Downstream accounting export consumer SPI (FinBridge adapter boundary).
 *
 * Tracks transport delivery acknowledgments separately from economic application receipts,
 * preserves unsupported schemas in quarantine, and never blocks core ledger commits.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface AccountingConsumer
{
    public function consumerRef(): string;

    /** @return list<string> */
    public function supportedExportSchemas(): array;

    public function acknowledgeDelivery(FinBridgeExportEnvelope $envelope, string $deliveryAttemptRef): AccountingDeliveryAck;

    public function apply(FinBridgeExportEnvelope $envelope): AccountingAppliedReceipt;

    public function quarantine(QuarantinedFactRecord $record): void;

    public function receiptFor(string $sourceFactRef): ?AccountingAppliedReceipt;

    /** @return list<AccountingDeliveryAck> */
    public function deliveryAcksFor(string $sourceFactRef): array;

    /** @return list<QuarantinedFactRecord> */
    public function quarantined(): array;
}
