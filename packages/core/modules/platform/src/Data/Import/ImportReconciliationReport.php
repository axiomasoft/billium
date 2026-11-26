<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Hashed read-only reconciliation report for an import batch, keeping local runtime evidence separated from external activation inputs.
 */
#[Api(since: '1.0')]
final readonly class ImportReconciliationReport
{
    public const string SCHEMA_V1 = 'external.import.reconciliation.v1';

    /**
     * @param  list<ImportCurrencyReconciliation>  $buckets
     * @param  array<string, array{obligationsDueMinor: int, obligationsOpenMinor: int, obligationsSettledMinor: int, obligationsCreditedMinor: int, paymentsCapturedMinor: int, paymentsRefundedMinor: int, paymentsAllocatedMinor: int, allocationsLiveMinor: int, conserved: bool}>  $ledgerConservationByCurrency
     * @param  list<array{sourceRecordId: string, sourceFactRef: string, factKind: string, currency: string, amountMinor: int, exportDigest: string}>  $accountingFacts
     * @param  list<array{code: string, detail: string, currency?: string, recordKind?: string, sourceRecordId?: string}>  $discrepancies
     * @param  list<array{inputId: string, status: string, requiredForCutover: bool}>  $externalActivationInputs
     */
    public function __construct(
        public string $installationRef,
        public string $environment,
        public string $sellerRef,
        public string $batchId,
        public string $manifestDigest,
        public ImportBatchStatus $batchStatus,
        public bool $reconciled,
        public array $buckets,
        public array $ledgerConservationByCurrency,
        public array $accountingFacts,
        public array $discrepancies,
        public array $externalActivationInputs,
        public string $digest,
        public DateTimeImmutable $generatedAt,
    ) {
        foreach (['manifestDigest' => $manifestDigest, 'digest' => $digest] as $field => $val) {
            if (preg_match('/^sha256:[0-9a-f]{64}$/', $val) !== 1) {
                throw new InvalidArgumentException("ImportReconciliationReport {$field} must be a valid sha256 digest");
            }
        }
    }

    /** @return array<string, mixed> */
    public function toWire(): array
    {
        return [
            'schema' => self::SCHEMA_V1,
            'installationRef' => $this->installationRef,
            'environment' => $this->environment,
            'sellerRef' => $this->sellerRef,
            'batchId' => $this->batchId,
            'manifestDigest' => $this->manifestDigest,
            'batchStatus' => $this->batchStatus->value,
            'reconciled' => $this->reconciled,
            'buckets' => array_map(static fn (ImportCurrencyReconciliation $b): array => $b->toWire(), $this->buckets),
            'ledgerConservationByCurrency' => $this->ledgerConservationByCurrency,
            'accountingFacts' => $this->accountingFacts,
            'discrepancies' => $this->discrepancies,
            'provenance' => [
                'evidenceScope' => 'local_runtime_only',
                'externalActivationInputsSeparated' => true,
                'externalActivationInputs' => $this->externalActivationInputs,
            ],
            'generatedAt' => $this->generatedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'digest' => $this->digest,
        ];
    }
}
