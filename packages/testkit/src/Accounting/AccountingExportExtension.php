<?php

declare(strict_types=1);

namespace Billium\Testkit\Accounting;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Platform\Contracts\Accounting\AccountingConsumer;
use Billium\Platform\Contracts\Accounting\AccountingMapper;
use Billium\Platform\Data\Accounting\AccountingMappingStatus;
use Billium\Platform\Data\Accounting\AccountingSourceRecord;
use Billium\Platform\Data\Accounting\FinBridgeExportEnvelope;
use Packman\Attributes\Api;
use RuntimeException;

/**
 * Minimal COV-299 accounting export extension demonstrating capability/version negotiation
 * and extension-owned export state without altering core financial migrations or writer ownership.
 */
#[Api(since: '1.0')]
final class AccountingExportExtension
{
    public const string EXTENSION_ID = 'axiomasoft.billium.accounting-export';

    public const string EXTENSION_VERSION = '1.0.0';

    /** @var list<class-string> */
    private const array REQUIRED_CAPABILITIES = [
        AccountingMapper::class,
    ];

    /** @var list<string> */
    private const array SUPPORTED_EXPORT_CONTRACTS = [
        FinBridgeExportEnvelope::SCHEMA_V1,
    ];

    /** @var list<AccountingSourceRecord> */
    private array $deferredQueue = [];

    /** @var array<string, string> */
    private array $extensionOwnedState = [];

    private int $deliveredCount = 0;

    private int $quarantinedCount = 0;

    public function extensionId(): string
    {
        return self::EXTENSION_ID;
    }

    public function version(): string
    {
        return self::EXTENSION_VERSION;
    }

    /** @return list<class-string> */
    public function requiredCapabilities(): array
    {
        return self::REQUIRED_CAPABILITIES;
    }

    /** @return list<string> */
    public function supportedExportContracts(): array
    {
        return self::SUPPORTED_EXPORT_CONTRACTS;
    }

    /** COV-299 invariant: extension owns its own state and never alters core financial migrations. */
    public function altersCoreMigrations(): bool
    {
        return false;
    }

    /**
     * COV-299 invariant: extension never writes core financial tables directly.
     *
     * @return list<string>
     */
    public function coreFinancialTablesWritten(): array
    {
        return [];
    }

    /**
     * Negotiates capability availability and FinBridge export contract version.
     *
     * @param  array<class-string, class-string>  $providedCapabilities
     * @return array{
     *     negotiated: bool,
     *     extensionId: string,
     *     extensionVersion: string,
     *     contractVersion: string,
     *     missingCapabilities: list<class-string>,
     *     reason: ?string
     * }
     */
    public function negotiate(array $providedCapabilities, string $requestedContractVersion = FinBridgeExportEnvelope::SCHEMA_V1): array
    {
        $missing = [];
        foreach (self::REQUIRED_CAPABILITIES as $capability) {
            if (! array_key_exists($capability, $providedCapabilities)) {
                $missing[] = $capability;
            }
        }
        if ($missing !== []) {
            return [
                'negotiated' => false,
                'extensionId' => self::EXTENSION_ID,
                'extensionVersion' => self::EXTENSION_VERSION,
                'contractVersion' => $requestedContractVersion,
                'missingCapabilities' => $missing,
                'reason' => 'missing_required_capability',
            ];
        }
        if (! in_array($requestedContractVersion, self::SUPPORTED_EXPORT_CONTRACTS, true)) {
            return [
                'negotiated' => false,
                'extensionId' => self::EXTENSION_ID,
                'extensionVersion' => self::EXTENSION_VERSION,
                'contractVersion' => $requestedContractVersion,
                'missingCapabilities' => [],
                'reason' => "unsupported_export_contract:{$requestedContractVersion}",
            ];
        }

        return [
            'negotiated' => true,
            'extensionId' => self::EXTENSION_ID,
            'extensionVersion' => self::EXTENSION_VERSION,
            'contractVersion' => $requestedContractVersion,
            'missingCapabilities' => [],
            'reason' => null,
        ];
    }

    /**
     * Enforces capability and version negotiation, throwing a typed ProblemException on unsupported versions.
     *
     * @param  array<class-string, class-string>  $providedCapabilities
     */
    public function requireNegotiated(array $providedCapabilities, string $requestedContractVersion = FinBridgeExportEnvelope::SCHEMA_V1): string
    {
        $result = $this->negotiate($providedCapabilities, $requestedContractVersion);
        if (! $result['negotiated']) {
            throw new ProblemException(
                ProblemCode::SchemaUnsupported,
                'Accounting export extension negotiation failed: '.($result['reason'] ?? 'unknown'),
            );
        }

        return $result['contractVersion'];
    }

    /**
     * Dispatches one source record through the mapper and consumer while keeping backpressure state
     * exclusively in extension-owned storage (never touching or blocking core ledger commits).
     */
    public function export(
        AccountingSourceRecord $record,
        AccountingMapper $mapper,
        AccountingConsumer $consumer,
        string $contractVersion = FinBridgeExportEnvelope::SCHEMA_V1,
    ): bool {
        $mapped = $mapper->map($record);
        if ($mapped->status === AccountingMappingStatus::QuarantinedUnsupportedSchema && $mapped->quarantined !== null) {
            $consumer->quarantine($mapped->quarantined);
            $this->quarantinedCount++;
            $this->extensionOwnedState[$mapped->quarantined->quarantineRef] = 'quarantined';

            return false;
        }
        if ($mapped->status !== AccountingMappingStatus::Mapped || $mapped->fact === null) {
            return false;
        }

        $envelope = $mapper->toFinBridge($mapped->fact, $contractVersion);
        $attemptRef = $record->deliveryAttemptRef ?? ('ext-attempt:'.($this->deliveredCount + count($this->deferredQueue) + 1));

        try {
            $consumer->acknowledgeDelivery($envelope, $attemptRef);
            $consumer->apply($envelope);
            $this->deliveredCount++;
            $this->extensionOwnedState[$envelope->sourceFactRef] = 'applied';

            return true;
        } catch (RuntimeException) {
            $this->deferredQueue[] = $record;
            $this->extensionOwnedState[$envelope->sourceFactRef] = 'deferred_backpressure';

            return false;
        }
    }

    /**
     * Retries deferred records after consumer backpressure clears.
     */
    public function drainDeferred(
        AccountingMapper $mapper,
        AccountingConsumer $consumer,
        string $contractVersion = FinBridgeExportEnvelope::SCHEMA_V1,
    ): int {
        $pending = $this->deferredQueue;
        $this->deferredQueue = [];
        $drained = 0;
        foreach ($pending as $record) {
            if ($this->export($record, $mapper, $consumer, $contractVersion)) {
                $drained++;
            }
        }

        return $drained;
    }

    public function deferredCount(): int
    {
        return count($this->deferredQueue);
    }

    public function deliveredCount(): int
    {
        return $this->deliveredCount;
    }

    public function quarantinedCount(): int
    {
        return $this->quarantinedCount;
    }

    /** @return array<string, string> */
    public function extensionOwnedState(): array
    {
        return $this->extensionOwnedState;
    }
}
