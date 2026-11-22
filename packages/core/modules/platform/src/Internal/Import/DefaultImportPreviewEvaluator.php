<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Import;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Contracts\MoneyMath;
use Billium\Contracts\Contracts\MoneyMathFactory;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Platform\Contracts\Import\ImportIdentityStore;
use Billium\Platform\Contracts\Import\ImportPreviewEvaluator;
use Billium\Platform\Data\Import\ImportBatchStatus;
use Billium\Platform\Data\Import\ImportCurrencyTotal;
use Billium\Platform\Data\Import\ImportManifest;
use Billium\Platform\Data\Import\ImportMappingStatus;
use Billium\Platform\Data\Import\ImportPreviewReport;
use Billium\Platform\Data\Import\ImportReceiptStatus;
use Billium\Platform\Data\Import\ImportRecordInput;
use Billium\Platform\Data\Import\ImportRecordKind;
use Billium\Platform\Data\Import\ImportRecordValidationResult;
use Billium\Platform\Data\Import\ImportValidationStatus;
use DateTimeImmutable;
use DateTimeZone;
use Override;
use Packman\Attributes\Internal;

/**
 * Pure read-only preview evaluator for external.import.v1 records.
 *
 * Classifies every record into ready, unanchored_evidence, rejected, conflict or already_applied without
 * fabricating missing money/currency/price/provider fields and keeps currency totals strictly separated.
 */
#[Internal]
final readonly class DefaultImportPreviewEvaluator implements ImportPreviewEvaluator
{
    private MoneyMath $math;

    public function __construct(
        private ImportIdentityStore $store,
        private Codec $codec = new Codec,
        ?MoneyMath $math = null,
    ) {
        $this->math = $math ?? MoneyMathFactory::create();
    }

    #[Override]
    public function evaluate(ImportManifest $manifest, array $records): ImportPreviewReport
    {
        $outcomes = [];
        $counts = [
            'total' => 0,
            'ready' => 0,
            'unanchored_evidence' => 0,
            'rejected' => 0,
            'conflict' => 0,
            'already_applied' => 0,
        ];
        /** @var array<string, array{sellerRef: string, currency: string, recordKind: ImportRecordKind, count: int, total: Money}> $buckets */
        $buckets = [];
        /** @var list<string> $referencesWithoutEvidence */
        $referencesWithoutEvidence = [];
        /** @var list<array{sourceRecordId: string, recordKind: string, existingMappingRef: string}> $duplicateMappings */
        $duplicateMappings = [];
        /** @var list<array{sourceRecordId: string, claimedPriceRef: string, reasonCode: string}> $planPriceMismatches */
        $planPriceMismatches = [];
        /** @var list<array{currency: string, delta: array{amount: string, currency: string}, reasonCode: string, detail?: string}> $unexplainedDeltas */
        $unexplainedDeltas = [];
        /** @var array<string, array{revision: int, payloadDigest: string}> $seenInBatch */
        $seenInBatch = [];

        foreach ($records as $record) {
            $identity = $record->identity;
            $identityDigest = $identity->identityDigest($this->codec);
            $sourceFactRef = $record->sourceFactRef ?? $identity->stableSourceFactRef($record->sourceRecordRevision, $this->codec);

            $outcome = $this->classifyRecord(
                $manifest,
                $record,
                $identityDigest,
                $sourceFactRef,
                $seenInBatch,
                $referencesWithoutEvidence,
                $duplicateMappings,
                $planPriceMismatches,
            );
            $outcomes[] = $outcome;
            $counts['total']++;
            $counts[$outcome->status->value]++;

            if ($record->money !== null && $outcome->status !== ImportValidationStatus::Rejected) {
                $bucketKey = $identity->sellerRef.'|'.$record->money->currency->code.'|'.$identity->recordKind->value;
                if (! isset($buckets[$bucketKey])) {
                    $buckets[$bucketKey] = [
                        'sellerRef' => $identity->sellerRef,
                        'currency' => $record->money->currency->code,
                        'recordKind' => $identity->recordKind,
                        'count' => 1,
                        'total' => $record->money,
                    ];
                } else {
                    $buckets[$bucketKey]['count']++;
                    $buckets[$bucketKey]['total'] = $this->math->add($buckets[$bucketKey]['total'], $record->money);
                }
            }
        }

        ksort($buckets, SORT_STRING);
        $totalsByCurrency = [];
        foreach ($buckets as $bucket) {
            $totalsByCurrency[] = new ImportCurrencyTotal(
                sellerRef: $bucket['sellerRef'],
                currency: $bucket['currency'],
                recordKind: $bucket['recordKind'],
                count: $bucket['count'],
                totalAmount: $bucket['total'],
                authoritativeCurrentBalance: false,
            );
        }

        // Compare manifest currency totals against evaluated per-currency totals without ever summing across currencies.
        $unexplainedDeltas = $this->computeCurrencyDeltas($manifest->totalsByCurrency, $totalsByCurrency);

        $batchStatus = $counts['conflict'] > 0
            ? ImportBatchStatus::Quarantined
            : ($counts['rejected'] > 0 ? ImportBatchStatus::Rejected : ImportBatchStatus::Validated);

        $generatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $body = [
            'schema' => ImportPreviewReport::SCHEMA_V1,
            'installationRef' => $manifest->installationRef,
            'environment' => $manifest->environment,
            'sellerRef' => $manifest->sellerRef,
            'batchId' => $manifest->batchId,
            'sourceSystem' => $manifest->sourceSystem,
            'sourceInstallation' => $manifest->sourceInstallation,
            'sourceBook' => $manifest->sourceBook,
            'sourceExportRevision' => $manifest->sourceExportRevision,
            'exportCutoff' => $manifest->exportCutoff->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'manifestDigest' => $manifest->manifestDigest,
            'batchStatus' => $batchStatus->value,
            'counts' => $counts,
            'totalsByCurrency' => array_map(static fn (ImportCurrencyTotal $t): array => $t->toWire(), $totalsByCurrency),
            'records' => array_map(static fn (ImportRecordValidationResult $r): array => $r->toWire(), $outcomes),
            'unexplainedDeltas' => $unexplainedDeltas,
            'referencesWithoutEvidence' => array_values(array_unique($referencesWithoutEvidence)),
            'duplicateMappings' => $duplicateMappings,
            'planPriceMismatches' => $planPriceMismatches,
            'generatedAt' => $generatedAt->format('Y-m-d\TH:i:s\Z'),
        ];
        if ($manifest->importPolicyVersion !== null) {
            $body['importPolicyVersion'] = $manifest->importPolicyVersion;
        }
        $digest = $this->codec->digest($body);

        return new ImportPreviewReport(
            installationRef: $manifest->installationRef,
            environment: $manifest->environment,
            sellerRef: $manifest->sellerRef,
            batchId: $manifest->batchId,
            sourceSystem: $manifest->sourceSystem,
            sourceInstallation: $manifest->sourceInstallation,
            sourceBook: $manifest->sourceBook,
            sourceExportRevision: $manifest->sourceExportRevision,
            exportCutoff: $manifest->exportCutoff,
            manifestDigest: $manifest->manifestDigest,
            batchStatus: $batchStatus,
            counts: $counts,
            totalsByCurrency: $totalsByCurrency,
            records: $outcomes,
            unexplainedDeltas: $unexplainedDeltas,
            referencesWithoutEvidence: array_values(array_unique($referencesWithoutEvidence)),
            duplicateMappings: $duplicateMappings,
            planPriceMismatches: $planPriceMismatches,
            digest: $digest,
            importPolicyVersion: $manifest->importPolicyVersion,
            generatedAt: $generatedAt,
        );
    }

    /**
     * @param  array<string, array{revision: int, payloadDigest: string}>  $seenInBatch
     * @param  list<string>  $referencesWithoutEvidence
     * @param  list<array{sourceRecordId: string, recordKind: string, existingMappingRef: string}>  $duplicateMappings
     * @param  list<array{sourceRecordId: string, claimedPriceRef: string, reasonCode: string}>  $planPriceMismatches
     */
    private function classifyRecord(
        ImportManifest $manifest,
        ImportRecordInput $record,
        string $identityDigest,
        string $sourceFactRef,
        array &$seenInBatch,
        array &$referencesWithoutEvidence,
        array &$duplicateMappings,
        array &$planPriceMismatches,
    ): ImportRecordValidationResult {
        $identity = $record->identity;

        // 1. Scope and manifest consistency check.
        if (
            $identity->installationRef !== $manifest->installationRef
            || $identity->environment !== $manifest->environment
            || $identity->sellerRef !== $manifest->sellerRef
        ) {
            return new ImportRecordValidationResult(
                identity: $identity,
                sourceRecordRevision: $record->sourceRecordRevision,
                sourceIdentityDigest: $identityDigest,
                payloadDigest: $record->payloadDigest,
                status: ImportValidationStatus::Rejected,
                rejectionCode: 'import.scope_mismatch',
            );
        }
        if ($record->manifestDigest !== $manifest->manifestDigest) {
            return new ImportRecordValidationResult(
                identity: $identity,
                sourceRecordRevision: $record->sourceRecordRevision,
                sourceIdentityDigest: $identityDigest,
                payloadDigest: $record->payloadDigest,
                status: ImportValidationStatus::Rejected,
                rejectionCode: 'import.manifest_digest_mismatch',
            );
        }

        // 2. Duplicate/conflict detection within the same batch.
        $batchKey = $identityDigest.':'.$record->sourceRecordRevision;
        if (isset($seenInBatch[$batchKey])) {
            $prior = $seenInBatch[$batchKey];
            if ($prior['payloadDigest'] !== $record->payloadDigest) {
                return new ImportRecordValidationResult(
                    identity: $identity,
                    sourceRecordRevision: $record->sourceRecordRevision,
                    sourceIdentityDigest: $identityDigest,
                    payloadDigest: $record->payloadDigest,
                    status: ImportValidationStatus::Conflict,
                    existingMappingRef: 'batch:'.$manifest->batchId.':'.$identity->sourceRecordId,
                );
            }
            $duplicateMappings[] = [
                'sourceRecordId' => $identity->sourceRecordId,
                'recordKind' => $identity->recordKind->value,
                'existingMappingRef' => 'batch:'.$manifest->batchId.':'.$identity->sourceRecordId,
            ];
        } else {
            $seenInBatch[$batchKey] = ['revision' => $record->sourceRecordRevision, 'payloadDigest' => $record->payloadDigest];
        }

        // 3. Existing durable mapping / tombstone / applied receipt check.
        $existingSameRev = $this->store->findMapping($identity, $record->sourceRecordRevision);
        if ($existingSameRev !== null) {
            if ($existingSameRev->status === ImportMappingStatus::Tombstoned) {
                return new ImportRecordValidationResult(
                    identity: $identity,
                    sourceRecordRevision: $record->sourceRecordRevision,
                    sourceIdentityDigest: $identityDigest,
                    payloadDigest: $record->payloadDigest,
                    status: ImportValidationStatus::Conflict,
                    existingMappingRef: $existingSameRev->mappingRef,
                );
            }
            if ($existingSameRev->payloadDigest !== $record->payloadDigest) {
                return new ImportRecordValidationResult(
                    identity: $identity,
                    sourceRecordRevision: $record->sourceRecordRevision,
                    sourceIdentityDigest: $identityDigest,
                    payloadDigest: $record->payloadDigest,
                    status: ImportValidationStatus::Conflict,
                    existingMappingRef: $existingSameRev->mappingRef,
                );
            }
            $applied = $this->store->findAppliedReceipt($identity, $record->sourceRecordRevision);
            if ($applied !== null) {
                $isAdoptedObservation = $applied->status === ImportReceiptStatus::ObservationQueued
                    && $existingSameRev->billiumTargetRef !== null;
                if ($applied->status === ImportReceiptStatus::Applied || $isAdoptedObservation) {
                    return new ImportRecordValidationResult(
                        identity: $identity,
                        sourceRecordRevision: $record->sourceRecordRevision,
                        sourceIdentityDigest: $identityDigest,
                        payloadDigest: $record->payloadDigest,
                        status: ImportValidationStatus::AlreadyApplied,
                        receiptRef: $existingSameRev->billiumTargetRef ?? $applied->billiumReceiptRef ?? $applied->receiptRef,
                        sourceFactRef: $existingSameRev->sourceFactRef,
                    );
                }

                return new ImportRecordValidationResult(
                    identity: $identity,
                    sourceRecordRevision: $record->sourceRecordRevision,
                    sourceIdentityDigest: $identityDigest,
                    payloadDigest: $record->payloadDigest,
                    status: ImportValidationStatus::Ready,
                    reasonCodes: ['import.observation_queued_pending_adoption'],
                    receiptRef: $applied->receiptRef,
                    sourceFactRef: $existingSameRev->sourceFactRef,
                );
            }
            $duplicateMappings[] = [
                'sourceRecordId' => $identity->sourceRecordId,
                'recordKind' => $identity->recordKind->value,
                'existingMappingRef' => $existingSameRev->mappingRef,
            ];
        } else {
            $latest = $this->store->findMapping($identity);
            if ($latest !== null && $latest->status === ImportMappingStatus::Tombstoned) {
                return new ImportRecordValidationResult(
                    identity: $identity,
                    sourceRecordRevision: $record->sourceRecordRevision,
                    sourceIdentityDigest: $identityDigest,
                    payloadDigest: $record->payloadDigest,
                    status: ImportValidationStatus::Conflict,
                    existingMappingRef: $latest->mappingRef,
                );
            }
        }

        // 4. Evidence completeness and unanchored_evidence classification (never fabricate missing money/price/provider fields).
        if ($record->evidenceRefs === []) {
            $referencesWithoutEvidence[] = $identity->sourceRecordId;
        }

        if ($identity->recordKind === ImportRecordKind::UnanchoredEvidence) {
            $missing = $record->missingEvidence !== [] ? $record->missingEvidence : ['authoritative_monetary_evidence'];
            $reasons = [];
            if (is_array($record->payload['unanchoredReasonCodes'] ?? null)) {
                foreach ($record->payload['unanchoredReasonCodes'] as $code) {
                    if (is_string($code) && trim($code) !== '') {
                        $reasons[] = $code;
                    }
                }
            }
            if ($reasons === []) {
                $reasons = ['import.unanchored_evidence'];
            }

            return new ImportRecordValidationResult(
                identity: $identity,
                sourceRecordRevision: $record->sourceRecordRevision,
                sourceIdentityDigest: $identityDigest,
                payloadDigest: $record->payloadDigest,
                status: ImportValidationStatus::UnanchoredEvidence,
                reasonCodes: array_values(array_unique($reasons)),
                missingEvidence: $missing,
                sourceFactRef: $sourceFactRef,
            );
        }

        if ($identity->recordKind === ImportRecordKind::ProvenObligation) {
            $missing = [];
            if ($record->money === null) {
                $missing[] = 'money';
            }
            if ($record->frozenPriceSnapshot === null) {
                $missing[] = 'frozenPriceSnapshot';
            }
            if ($missing !== []) {
                return new ImportRecordValidationResult(
                    identity: $identity,
                    sourceRecordRevision: $record->sourceRecordRevision,
                    sourceIdentityDigest: $identityDigest,
                    payloadDigest: $record->payloadDigest,
                    status: ImportValidationStatus::UnanchoredEvidence,
                    reasonCodes: ['import.missing_proven_price_basis'],
                    missingEvidence: $missing,
                    sourceFactRef: $sourceFactRef,
                );
            }
            $priceCurrency = $record->frozenPriceSnapshot['currency'] ?? null;
            if (is_string($priceCurrency) && $priceCurrency !== $record->money->currency->code) {
                $claimedRef = is_string($record->frozenPriceSnapshot['priceVersionRef'] ?? null)
                    ? $record->frozenPriceSnapshot['priceVersionRef']
                    : 'unknown';
                $planPriceMismatches[] = [
                    'sourceRecordId' => $identity->sourceRecordId,
                    'claimedPriceRef' => $claimedRef,
                    'reasonCode' => 'import.price_currency_mismatch',
                ];

                return new ImportRecordValidationResult(
                    identity: $identity,
                    sourceRecordRevision: $record->sourceRecordRevision,
                    sourceIdentityDigest: $identityDigest,
                    payloadDigest: $record->payloadDigest,
                    status: ImportValidationStatus::Rejected,
                    rejectionCode: 'import.price_currency_mismatch',
                );
            }
        }

        if (in_array($identity->recordKind, [ImportRecordKind::ProvenPaymentEvidence, ImportRecordKind::ProvenRefundEvidence], true)) {
            $missing = [];
            if ($record->money === null) {
                $missing[] = 'money';
            }
            if ($record->fundingRefs === null) {
                $missing[] = 'fundingRefs';
            }
            if ($identity->recordKind === ImportRecordKind::ProvenRefundEvidence && ! is_string($record->fundingRefs['originalOperationRef'] ?? null)) {
                $missing[] = 'fundingRefs.originalOperationRef';
            }
            if ($missing !== []) {
                return new ImportRecordValidationResult(
                    identity: $identity,
                    sourceRecordRevision: $record->sourceRecordRevision,
                    sourceIdentityDigest: $identityDigest,
                    payloadDigest: $record->payloadDigest,
                    status: ImportValidationStatus::UnanchoredEvidence,
                    reasonCodes: ['import.missing_authoritative_funding_evidence'],
                    missingEvidence: $missing,
                    sourceFactRef: $sourceFactRef,
                );
            }
            $fundingStatus = $record->fundingRefs['fundingStatus'] ?? null;
            if (is_string($fundingStatus) && in_array(strtolower(trim($fundingStatus)), ['unknown', 'accepted-unknown', 'pending_unknown', 'unverified'], true)) {
                return new ImportRecordValidationResult(
                    identity: $identity,
                    sourceRecordRevision: $record->sourceRecordRevision,
                    sourceIdentityDigest: $identityDigest,
                    payloadDigest: $record->payloadDigest,
                    status: ImportValidationStatus::UnanchoredEvidence,
                    reasonCodes: ['import.unresolved_remote_funding_status'],
                    missingEvidence: ['fundingRefs.verifiedTerminalOutcome'],
                    sourceFactRef: $sourceFactRef,
                );
            }
        }

        if ($identity->recordKind === ImportRecordKind::ProvenInvoiceHistory) {
            if (! is_string($record->legalDocument['originalIssuer'] ?? null) || ! is_string($record->legalDocument['sourceNumber'] ?? null)) {
                return new ImportRecordValidationResult(
                    identity: $identity,
                    sourceRecordRevision: $record->sourceRecordRevision,
                    sourceIdentityDigest: $identityDigest,
                    payloadDigest: $record->payloadDigest,
                    status: ImportValidationStatus::UnanchoredEvidence,
                    reasonCodes: ['import.missing_historical_legal_number'],
                    missingEvidence: ['legalDocument.originalIssuer', 'legalDocument.sourceNumber'],
                    sourceFactRef: $sourceFactRef,
                );
            }
        }

        return new ImportRecordValidationResult(
            identity: $identity,
            sourceRecordRevision: $record->sourceRecordRevision,
            sourceIdentityDigest: $identityDigest,
            payloadDigest: $record->payloadDigest,
            status: ImportValidationStatus::Ready,
            sourceFactRef: $sourceFactRef,
        );
    }

    /**
     * @param  list<ImportCurrencyTotal>  $manifestTotals
     * @param  list<ImportCurrencyTotal>  $evaluatedTotals
     * @return list<array{currency: string, delta: array{amount: string, currency: string}, reasonCode: string, detail?: string}>
     */
    private function computeCurrencyDeltas(array $manifestTotals, array $evaluatedTotals): array
    {
        if ($manifestTotals === []) {
            return [];
        }
        /** @var array<string, int> $manifestMinor */
        $manifestMinor = [];
        foreach ($manifestTotals as $total) {
            $manifestMinor[$total->currency] = ($manifestMinor[$total->currency] ?? 0) + $total->totalAmount->minor;
        }
        /** @var array<string, int> $evaluatedMinor */
        $evaluatedMinor = [];
        foreach ($evaluatedTotals as $total) {
            $evaluatedMinor[$total->currency] = ($evaluatedMinor[$total->currency] ?? 0) + $total->totalAmount->minor;
        }
        $currencies = array_values(array_unique(array_merge(array_keys($manifestMinor), array_keys($evaluatedMinor))));
        sort($currencies, SORT_STRING);

        $deltas = [];
        foreach ($currencies as $currencyCode) {
            $diff = ($evaluatedMinor[$currencyCode] ?? 0) - ($manifestMinor[$currencyCode] ?? 0);
            if ($diff !== 0) {
                $deltas[] = [
                    'currency' => $currencyCode,
                    'delta' => new Money($diff, Currency::of($currencyCode))->toWire(),
                    'reasonCode' => 'import.manifest_total_variance',
                    'detail' => 'Evaluated record total differs from manifest declared currency total',
                ];
            }
        }

        return $deltas;
    }
}
