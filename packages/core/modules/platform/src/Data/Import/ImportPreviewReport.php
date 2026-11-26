<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Typed external.import.preview.v1 validation report with isolated per-currency totals and explicit gaps.
 */
#[Api(since: '1.0')]
final readonly class ImportPreviewReport
{
    public const string SCHEMA_V1 = 'external.import.preview.v1';

    /**
     * @param  array{total: int, ready: int, unanchored_evidence: int, rejected: int, conflict: int, already_applied: int}  $counts
     * @param  list<ImportCurrencyTotal>  $totalsByCurrency
     * @param  list<ImportRecordValidationResult>  $records
     * @param  list<array{currency: string, delta: array{amount: string, currency: string}, reasonCode: string, detail?: string}>  $unexplainedDeltas
     * @param  list<string>  $referencesWithoutEvidence
     * @param  list<array{sourceRecordId: string, recordKind: string, existingMappingRef: string}>  $duplicateMappings
     * @param  list<array{sourceRecordId: string, claimedPriceRef: string, reasonCode: string}>  $planPriceMismatches
     */
    public function __construct(
        public string $installationRef,
        public string $environment,
        public string $sellerRef,
        public string $batchId,
        public string $sourceSystem,
        public string $sourceInstallation,
        public string $sourceBook,
        public int $sourceExportRevision,
        public DateTimeImmutable $exportCutoff,
        public string $manifestDigest,
        public ImportBatchStatus $batchStatus,
        public array $counts,
        public array $totalsByCurrency,
        public array $records,
        public array $unexplainedDeltas,
        public array $referencesWithoutEvidence,
        public array $duplicateMappings,
        public array $planPriceMismatches,
        public string $digest,
        public ?string $importPolicyVersion = null,
        public ?DateTimeImmutable $generatedAt = null,
    ) {
        if (preg_match('/^sha256:[0-9a-f]{64}$/', $digest) !== 1) {
            throw new InvalidArgumentException('ImportPreviewReport digest must be a valid sha256 digest');
        }
        $sum = $counts['ready']
            + $counts['unanchored_evidence']
            + $counts['rejected']
            + $counts['conflict']
            + $counts['already_applied'];
        if ($counts['total'] !== $sum || $counts['total'] !== count($records)) {
            throw new InvalidArgumentException('ImportPreviewReport counts must reconcile exactly with evaluated records');
        }
    }

    /** @return array<string, mixed> */
    public function toWire(): array
    {
        $wire = [
            'schema' => self::SCHEMA_V1,
            'installationRef' => $this->installationRef,
            'environment' => $this->environment,
            'sellerRef' => $this->sellerRef,
            'batchId' => $this->batchId,
            'sourceSystem' => $this->sourceSystem,
            'sourceInstallation' => $this->sourceInstallation,
            'sourceBook' => $this->sourceBook,
            'sourceExportRevision' => $this->sourceExportRevision,
            'exportCutoff' => $this->exportCutoff->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'manifestDigest' => $this->manifestDigest,
            'batchStatus' => $this->batchStatus->value,
            'counts' => $this->counts,
            'totalsByCurrency' => array_map(static fn (ImportCurrencyTotal $t): array => $t->toWire(), $this->totalsByCurrency),
            'records' => array_map(static fn (ImportRecordValidationResult $r): array => $r->toWire(), $this->records),
            'unexplainedDeltas' => $this->unexplainedDeltas,
            'referencesWithoutEvidence' => $this->referencesWithoutEvidence,
            'duplicateMappings' => $this->duplicateMappings,
            'planPriceMismatches' => $this->planPriceMismatches,
            'digest' => $this->digest,
        ];
        if ($this->importPolicyVersion !== null) {
            $wire['importPolicyVersion'] = $this->importPolicyVersion;
        }
        if ($this->generatedAt !== null) {
            $wire['generatedAt'] = $this->generatedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }

        return $wire;
    }
}
