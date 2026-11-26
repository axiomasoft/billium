<?php

declare(strict_types=1);

namespace Billium\Testkit\Accounting;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Platform\Contracts\Accounting\AccountingConsumer;
use Billium\Platform\Contracts\Accounting\AccountingMapper;
use Billium\Platform\Data\Accounting\AccountingAppliedReceipt;
use Billium\Platform\Data\Accounting\AccountingDeliveryAck;
use Billium\Platform\Data\Accounting\AccountingFactCategory;
use Billium\Platform\Data\Accounting\AccountingFactKind;
use Billium\Platform\Data\Accounting\AccountingMappingResult;
use Billium\Platform\Data\Accounting\AccountingMappingStatus;
use Billium\Platform\Data\Accounting\AccountingSourceRecord;
use Billium\Platform\Data\Accounting\FinBridgeExportEnvelope;
use Billium\Platform\Data\Accounting\QuarantinedFactRecord;
use DateTimeImmutable;
use Override;
use Packman\Attributes\Api;
use RuntimeException;

/**
 * Deterministic in-memory FinBridge consumer test double for P6.6 conformance and integration tests.
 *
 * Separates transport delivery ACKs from economic applied receipts, enforces idempotency under N replays,
 * quarantines unsupported schemas with post-upgrade recovery, supports outage/backpressure simulation,
 * and never performs GL writes.
 */
#[Api(since: '1.0')]
final class FakeFinBridgeConsumer implements AccountingConsumer
{
    /** @var array<string, list<AccountingDeliveryAck>> */
    private array $deliveryAcks = [];

    /** @var array<string, AccountingAppliedReceipt> */
    private array $appliedReceipts = [];

    /** @var array<string, FinBridgeExportEnvelope> */
    private array $appliedEnvelopes = [];

    /** @var array<string, QuarantinedFactRecord> */
    private array $quarantinedRecords = [];

    private bool $backpressured = false;

    private string $backpressureReason = 'consumer_backpressure';

    private int $rejectedDeliveries = 0;

    private int $deliverySequence = 0;

    private int $receiptSequence = 0;

    /**
     * @param  list<string>  $supportedExportSchemas
     */
    public function __construct(
        private readonly string $consumerRef = 'finbridge:fake-consumer',
        private readonly array $supportedExportSchemas = [FinBridgeExportEnvelope::SCHEMA_V1],
    ) {}

    #[Override]
    public function consumerRef(): string
    {
        return $this->consumerRef;
    }

    #[Override]
    public function supportedExportSchemas(): array
    {
        return $this->supportedExportSchemas;
    }

    public function setBackpressure(bool $active, string $reason = 'consumer_backpressure'): void
    {
        $this->backpressured = $active;
        $this->backpressureReason = $reason;
    }

    public function isBackpressured(): bool
    {
        return $this->backpressured;
    }

    public function rejectedDeliveryCount(): int
    {
        return $this->rejectedDeliveries;
    }

    #[Override]
    public function acknowledgeDelivery(FinBridgeExportEnvelope $envelope, string $deliveryAttemptRef): AccountingDeliveryAck
    {
        if ($this->backpressured) {
            $this->rejectedDeliveries++;

            throw new RuntimeException("Accounting consumer unavailable: {$this->backpressureReason}");
        }

        $existing = $this->deliveryAcks[$envelope->sourceFactRef] ?? [];
        $duplicate = false;
        foreach ($existing as $ack) {
            if ($ack->deliveryAttemptRef === $deliveryAttemptRef) {
                $duplicate = true;
                break;
            }
        }

        $this->deliverySequence++;
        $ack = new AccountingDeliveryAck(
            ackRef: sprintf('ack:%s:%d', $this->consumerRef, $this->deliverySequence),
            sourceFactRef: $envelope->sourceFactRef,
            deliveryAttemptRef: $deliveryAttemptRef,
            consumerRef: $this->consumerRef,
            acknowledgedAt: new DateTimeImmutable,
            duplicateDelivery: $duplicate || $existing !== [],
        );
        $this->deliveryAcks[$envelope->sourceFactRef][] = $ack;

        return $ack;
    }

    #[Override]
    public function apply(FinBridgeExportEnvelope $envelope): AccountingAppliedReceipt
    {
        if ($this->backpressured) {
            $this->rejectedDeliveries++;

            throw new RuntimeException("Accounting consumer unavailable: {$this->backpressureReason}");
        }
        if (! in_array($envelope->schemaVersion, $this->supportedExportSchemas, true)) {
            throw new ProblemException(
                ProblemCode::SchemaUnsupported,
                "Consumer '{$this->consumerRef}' does not support export schema '{$envelope->schemaVersion}'",
            );
        }

        if (isset($this->appliedReceipts[$envelope->sourceFactRef])) {
            $original = $this->appliedReceipts[$envelope->sourceFactRef];

            return new AccountingAppliedReceipt(
                receiptRef: $original->receiptRef,
                sourceFactRef: $original->sourceFactRef,
                category: $original->category,
                factKind: $original->factKind,
                money: $original->money,
                consumerRef: $original->consumerRef,
                snapshotDigest: $original->snapshotDigest,
                appliedAt: $original->appliedAt,
                replayed: true,
            );
        }

        $this->receiptSequence++;
        $receipt = new AccountingAppliedReceipt(
            receiptRef: sprintf('applied:%s:%d', $this->consumerRef, $this->receiptSequence),
            sourceFactRef: $envelope->sourceFactRef,
            category: $envelope->category,
            factKind: $envelope->factKind,
            money: new Money($envelope->amountMinor, Currency::of($envelope->currency)),
            consumerRef: $this->consumerRef,
            snapshotDigest: $envelope->snapshotDigest,
            appliedAt: new DateTimeImmutable,
            replayed: false,
        );
        $this->appliedReceipts[$envelope->sourceFactRef] = $receipt;
        $this->appliedEnvelopes[$envelope->sourceFactRef] = $envelope;

        return $receipt;
    }

    #[Override]
    public function quarantine(QuarantinedFactRecord $record): void
    {
        $this->quarantinedRecords[$record->quarantineRef] ??= $record;
    }

    #[Override]
    public function receiptFor(string $sourceFactRef): ?AccountingAppliedReceipt
    {
        return $this->appliedReceipts[$sourceFactRef] ?? null;
    }

    #[Override]
    public function deliveryAcksFor(string $sourceFactRef): array
    {
        return $this->deliveryAcks[$sourceFactRef] ?? [];
    }

    #[Override]
    public function quarantined(): array
    {
        return array_values($this->quarantinedRecords);
    }

    /**
     * Convenience end-to-end ingestion helper: maps source record, quarantines unsupported schemas,
     * translates mapped facts via FinBridge contract, acknowledges delivery, and applies idempotently.
     */
    public function consume(
        AccountingSourceRecord $record,
        AccountingMapper $mapper,
        ?string $deliveryAttemptRef = null,
        string $exportContractVersion = FinBridgeExportEnvelope::SCHEMA_V1,
    ): AccountingMappingResult {
        $result = $mapper->map($record);
        if ($result->status === AccountingMappingStatus::QuarantinedUnsupportedSchema && $result->quarantined !== null) {
            $this->quarantine($result->quarantined);

            return $result;
        }
        if ($result->status !== AccountingMappingStatus::Mapped || $result->fact === null) {
            return $result;
        }

        $envelope = $mapper->toFinBridge($result->fact, $exportContractVersion);
        $attempt = $deliveryAttemptRef ?? $record->deliveryAttemptRef ?? ('attempt:'.($this->deliverySequence + 1));
        $this->acknowledgeDelivery($envelope, $attempt);
        $this->apply($envelope);

        return $result;
    }

    /**
     * Replays all quarantined records through an upgraded mapper, resolving newly supported records
     * without creating duplicates and keeping still-unsupported records in quarantine.
     *
     * @return list<AccountingMappingResult>
     */
    public function replayQuarantined(
        AccountingMapper $upgradedMapper,
        string $exportContractVersion = FinBridgeExportEnvelope::SCHEMA_V1,
    ): array {
        $results = [];
        $pending = array_values($this->quarantinedRecords);
        foreach ($pending as $record) {
            $result = $upgradedMapper->map($record->sourceRecord);
            $results[] = $result;
            if ($result->status === AccountingMappingStatus::Mapped && $result->fact !== null) {
                $envelope = $upgradedMapper->toFinBridge($result->fact, $exportContractVersion);
                $this->acknowledgeDelivery($envelope, 'quarantine-replay:'.$record->quarantineRef);
                $this->apply($envelope);
                unset($this->quarantinedRecords[$record->quarantineRef]);
            }
        }

        return $results;
    }

    public function appliedCount(?AccountingFactCategory $category = null, ?AccountingFactKind $kind = null): int
    {
        $count = 0;
        foreach ($this->appliedReceipts as $receipt) {
            if ($category !== null && $receipt->category !== $category) {
                continue;
            }
            if ($kind !== null && $receipt->factKind !== $kind) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    public function totalMinorByCategory(AccountingFactCategory $category, string $currency): int
    {
        $sum = 0;
        foreach ($this->appliedReceipts as $receipt) {
            if ($receipt->category === $category && $receipt->money->currency->code === $currency) {
                $sum += $receipt->money->minor;
            }
        }

        return $sum;
    }

    public function totalMinorByKind(AccountingFactKind $kind, string $currency): int
    {
        $sum = 0;
        foreach ($this->appliedReceipts as $receipt) {
            if ($receipt->factKind === $kind && $receipt->money->currency->code === $currency) {
                $sum += $receipt->money->minor;
            }
        }

        return $sum;
    }

    public function deliveryAckCount(?string $sourceFactRef = null): int
    {
        if ($sourceFactRef !== null) {
            return count($this->deliveryAcks[$sourceFactRef] ?? []);
        }
        $total = 0;
        foreach ($this->deliveryAcks as $list) {
            $total += count($list);
        }

        return $total;
    }

    /** @return list<FinBridgeExportEnvelope> */
    public function appliedEnvelopes(?AccountingFactCategory $category = null, ?AccountingFactKind $kind = null): array
    {
        $items = [];
        foreach ($this->appliedEnvelopes as $envelope) {
            if ($category !== null && $envelope->category !== $category) {
                continue;
            }
            if ($kind !== null && $envelope->factKind !== $kind) {
                continue;
            }
            $items[] = $envelope;
        }

        return $items;
    }

    /** @return list<AccountingAppliedReceipt> */
    public function appliedReceipts(): array
    {
        return array_values($this->appliedReceipts);
    }

    /** FinBridge adapter translates economic facts only and never writes GL entries. */
    public function glWriteCount(): int
    {
        return 0;
    }
}
