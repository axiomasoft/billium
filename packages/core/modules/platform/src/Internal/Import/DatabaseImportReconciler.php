<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Import;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Contracts\MoneyMath;
use Billium\Contracts\Contracts\MoneyMathFactory;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Platform\Contracts\Accounting\AccountingMapper;
use Billium\Platform\Contracts\Import\ImportMonetaryPort;
use Billium\Platform\Contracts\Import\ImportReconciler;
use Billium\Platform\Data\Accounting\AccountingSourceRecord;
use Billium\Platform\Data\Import\ImportBatchStatus;
use Billium\Platform\Data\Import\ImportCurrencyReconciliation;
use Billium\Platform\Data\Import\ImportObservationOutcome;
use Billium\Platform\Data\Import\ImportReceiptStatus;
use Billium\Platform\Data\Import\ImportReconciliationReport;
use Billium\Platform\Data\Import\ImportRecordKind;
use Billium\Platform\Data\Import\ImportSourceIdentity;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Override;
use Packman\Attributes\Internal;

/**
 * Read-only per-(sellerRef, currency, recordKind) reconciler producing a canonical hashed ImportReconciliationReport.
 *
 * Strictly performs zero writes and zero provider/GL side effects, never sums across currencies,
 * verifies Ledger conservation per currency, verifies stable AccountingFactMapper sourceFactRefs for imported
 * actual payment/refund facts (IM-11), and explicitly separates deferred external activation inputs.
 */
#[Internal]
final readonly class DatabaseImportReconciler implements ImportReconciler
{
    /**
     * Staged rows resolved per mapping/receipt round trip; bounds both the bind payload and the per-chunk result.
     */
    private const int LOOKUP_CHUNK_SIZE = 500;

    private MoneyMath $math;

    /**
     * @param  Closure(): CallerContext  $callers
     * @param  Closure(): ImportMonetaryPort  $monetaryPort
     */
    public function __construct(
        private DatabaseManager $databases,
        private BilliumOptions $options,
        private AccountingMapper $accountingMapper,
        private Closure $callers,
        private Closure $monetaryPort,
        private Codec $codec = new Codec,
        ?MoneyMath $math = null,
    ) {
        $this->math = $math ?? MoneyMathFactory::create();
    }

    #[Override]
    public function reconcileBatch(string $batchId, string $migrationSourceAuthority = 'standalone-billium'): ImportReconciliationReport
    {
        if (trim($batchId) === '' || strlen($batchId) > 200) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Import batchId must be a bounded nonempty string');
        }
        $caller = ($this->callers)()->current();
        $db = $this->connection();

        $batch = $db->selectOne(
            'SELECT * FROM public.billium_import_batch '
            .'WHERE installation_ref = ? AND environment = ? AND seller_ref = ? AND batch_id = ?',
            [$caller->installationRef, $caller->environment, $caller->sellerRef, $batchId],
        );
        if (! is_object($batch)) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, "Unknown import batch '{$batchId}' in current seller/environment scope");
        }

        $batchPk = self::intField($batch, 'id');
        $manifestDigest = self::strField($batch, 'manifest_digest');
        $expectedRecordCount = self::intField($batch, 'record_count');
        $expectedRecordsChecksum = self::strField($batch, 'records_checksum');
        $batchStatus = ImportBatchStatus::from(self::strField($batch, 'status'));
        $rawManifestPayload = json_decode(self::strField($batch, 'manifest_payload'), true, 512, JSON_THROW_ON_ERROR);
        $declaredTotals = is_array($rawManifestPayload) && is_array($rawManifestPayload['totalsByCurrency'] ?? null)
            ? $rawManifestPayload['totalsByCurrency']
            : [];

        $records = ImportStagedRecordHydrator::objectRows($db->select(
            'SELECT * FROM public.billium_import_record WHERE batch_pk = ? ORDER BY id ASC',
            [$batchPk],
        ));
        $inventoryCheck = ImportStagedRecordHydrator::verifyStagedRowsInventory(
            $records,
            $expectedRecordCount,
            $expectedRecordsChecksum,
            $this->codec,
        );

        /**
         * @var array<string, array{
         *   sellerRef: string,
         *   currency: string,
         *   recordKind: ImportRecordKind,
         *   expectedCount: int,
         *   expectedTotal: Money,
         *   appliedCount: int,
         *   appliedTotal: Money,
         *   queuedCount: int,
         *   queuedTotal: Money,
         *   unanchoredCount: int,
         *   unanchoredTotal: Money
         * }> $buckets
         */
        $buckets = [];
        /** @var list<array{code: string, detail: string, currency?: string, recordKind?: string, sourceRecordId?: string}> $discrepancies */
        $discrepancies = [];
        if (! $inventoryCheck['countMatches']) {
            $discrepancies[] = [
                'code' => 'import.incomplete_staged_inventory',
                'detail' => sprintf(
                    'Manifest declared %d record(s), staged batch contains %d record(s)',
                    $expectedRecordCount,
                    count($records),
                ),
            ];
        }
        if (! $inventoryCheck['checksumMatches']) {
            $discrepancies[] = [
                'code' => 'import.records_checksum_mismatch',
                'detail' => sprintf(
                    'Staged records inventory checksum does not match manifest recordsChecksum (%s)',
                    $expectedRecordsChecksum,
                ),
            ];
        }
        /** @var list<array{sourceRecordId: string, sourceFactRef: string, factKind: string, currency: string, amountMinor: int, exportDigest: string}> $accountingFacts */
        $accountingFacts = [];
        /** @var array<string, true> $seenAccountingFactRefs */
        $seenAccountingFactRefs = [];
        $pendingQueuedOrParked = 0;

        $envelopeBuilder = new ImportObservationEnvelopeBuilder($this->codec);
        $monetary = ($this->monetaryPort)();

        foreach (array_chunk($records, self::LOOKUP_CHUNK_SIZE) as $chunk) {
            $lookups = $this->resolveChunk($db, $chunk);
            foreach ($chunk as $position => $row) {
                $sourceRecordId = self::strField($row, 'source_record_id');
                $recordKind = ImportRecordKind::from(self::strField($row, 'record_kind'));
                $revision = self::intField($row, 'source_record_revision');
                $validationStatus = self::strField($row, 'validation_status');
                $currencyCode = self::optStrField($row, 'currency');
                $amountMinor = self::optIntField($row, 'amount_minor');
                $payload = self::decodeArray(self::strField($row, 'payload'));

                $identity = new ImportSourceIdentity(
                    installationRef: self::strField($row, 'installation_ref'),
                    environment: self::strField($row, 'environment'),
                    sellerRef: self::strField($row, 'seller_ref'),
                    sourceSystem: self::strField($row, 'source_system'),
                    sourceInstallation: self::strField($row, 'source_installation'),
                    sourceBook: self::strField($row, 'source_book'),
                    sourceRecordId: $sourceRecordId,
                    recordKind: $recordKind,
                );

                if ($validationStatus === 'conflict') {
                    $discrepancies[] = [
                        'sourceRecordId' => $sourceRecordId,
                        'recordKind' => $recordKind->value,
                        'code' => 'import.record_conflict',
                        'detail' => 'Source identity/revision has a conflicting payload digest or tombstoned mapping',
                    ];
                } elseif ($validationStatus === 'rejected') {
                    $discrepancies[] = [
                        'sourceRecordId' => $sourceRecordId,
                        'recordKind' => $recordKind->value,
                        'code' => self::optStrField($row, 'rejection_code') ?? 'import.record_rejected',
                        'detail' => 'Record rejected during validation/apply',
                    ];
                }

                $mappingTargetRef = $lookups[$position]['mappingTargetRef'];
                $receiptStatus = $lookups[$position]['receiptStatus'];

                // Classify the record's actual runtime disposition without claiming queued observations as applied.
                $disposition = 'pending';
                if ($validationStatus === 'unanchored_evidence' || $recordKind === ImportRecordKind::UnanchoredEvidence) {
                    $disposition = 'unanchored';
                } elseif (in_array($recordKind, [ImportRecordKind::ProvenPaymentEvidence, ImportRecordKind::ProvenRefundEvidence], true)) {
                    if ($mappingTargetRef !== null) {
                        $disposition = 'applied';
                        $opKind = $recordKind === ImportRecordKind::ProvenRefundEvidence ? 'refund' : 'charge';
                        $funding = is_array($payload['fundingRefs'] ?? null) ? $payload['fundingRefs'] : [];
                        $origRefs = is_array($payload['originalBusinessRefs'] ?? null) ? $payload['originalBusinessRefs'] : [];
                        $opRef = $this->resolveOperationRefForRow($sourceRecordId, $recordKind, $funding, $origRefs);
                        $obsOutcome = $monetary->findObservationOutcome($migrationSourceAuthority, $opRef, $opKind, max(1, $revision));
                        if ($obsOutcome->status === ImportObservationOutcome::STATUS_HELD_DISCREPANCY) {
                            $discrepancies[] = [
                                'sourceRecordId' => $sourceRecordId,
                                'recordKind' => $recordKind->value,
                                'code' => 'import.observation_held_discrepancy',
                                'detail' => 'Observed payment/refund projected with financial hold discrepancy',
                            ];
                        }
                        if ($currencyCode !== null && $amountMinor !== null && $amountMinor > 0) {
                            $factEntry = $this->evaluateAccountingFact(
                                identity: $identity,
                                payload: $payload,
                                money: new Money($amountMinor, Currency::of($currencyCode)),
                                occurredAt: new DateTimeImmutable(self::strField($row, 'event_business_date')),
                                envelopeBuilder: $envelopeBuilder,
                            );
                            if ($factEntry !== null) {
                                if (isset($seenAccountingFactRefs[$factEntry['sourceFactRef']])) {
                                    $discrepancies[] = [
                                        'sourceRecordId' => $sourceRecordId,
                                        'currency' => $currencyCode,
                                        'recordKind' => $recordKind->value,
                                        'code' => 'import.duplicate_accounting_fact_ref',
                                        'detail' => 'Duplicate accounting sourceFactRef within reconciled batch',
                                    ];
                                }
                                $seenAccountingFactRefs[$factEntry['sourceFactRef']] = true;
                                $accountingFacts[] = $factEntry;
                            }
                        }
                    } elseif ($receiptStatus === ImportReceiptStatus::ObservationQueued) {
                        $disposition = 'queued_or_parked';
                        $pendingQueuedOrParked++;
                    } else {
                        $pendingQueuedOrParked++;
                    }
                } else {
                    if ($receiptStatus === ImportReceiptStatus::Applied || $mappingTargetRef !== null) {
                        $disposition = 'applied';
                    } elseif (! in_array($validationStatus, ['rejected', 'conflict'], true)) {
                        $pendingQueuedOrParked++;
                    }
                }

                if ($currencyCode !== null && $amountMinor !== null && $validationStatus !== 'rejected') {
                    $money = new Money($amountMinor, Currency::of($currencyCode));
                    $zero = new Money(0, Currency::of($currencyCode));
                    $key = $identity->sellerRef.'|'.$currencyCode.'|'.$recordKind->value;
                    $buckets[$key] ??= [
                        'sellerRef' => $identity->sellerRef,
                        'currency' => $currencyCode,
                        'recordKind' => $recordKind,
                        'expectedCount' => 0,
                        'expectedTotal' => $zero,
                        'appliedCount' => 0,
                        'appliedTotal' => $zero,
                        'queuedCount' => 0,
                        'queuedTotal' => $zero,
                        'unanchoredCount' => 0,
                        'unanchoredTotal' => $zero,
                    ];
                    $buckets[$key]['expectedCount']++;
                    $buckets[$key]['expectedTotal'] = $this->math->add($buckets[$key]['expectedTotal'], $money);

                    if ($disposition === 'applied') {
                        $buckets[$key]['appliedCount']++;
                        $buckets[$key]['appliedTotal'] = $this->math->add($buckets[$key]['appliedTotal'], $money);
                    } elseif ($disposition === 'queued_or_parked') {
                        $buckets[$key]['queuedCount']++;
                        $buckets[$key]['queuedTotal'] = $this->math->add($buckets[$key]['queuedTotal'], $money);
                    } elseif ($disposition === 'unanchored') {
                        $buckets[$key]['unanchoredCount']++;
                        $buckets[$key]['unanchoredTotal'] = $this->math->add($buckets[$key]['unanchoredTotal'], $money);
                    }
                }
            }
        }

        // Cross-check manifest declared per-currency/per-recordKind totals without summing across currencies.
        foreach ($declaredTotals as $declared) {
            if (! is_array($declared)) {
                continue;
            }
            $c = is_string($declared['currency'] ?? null) ? $declared['currency'] : null;
            $k = is_string($declared['recordKind'] ?? null) ? ImportRecordKind::tryFrom($declared['recordKind']) : null;
            $s = is_string($declared['sellerRef'] ?? null) ? $declared['sellerRef'] : $caller->sellerRef;
            if ($c === null || $k === null || ! is_array($declared['totalAmount'] ?? null)) {
                continue;
            }
            $declaredMoney = Money::fromWire($declared['totalAmount']);
            $key = $s.'|'.$c.'|'.$k->value;
            $stagedMoney = $buckets[$key]['expectedTotal'] ?? new Money(0, Currency::of($c));
            if ($stagedMoney->minor !== $declaredMoney->minor) {
                $discrepancies[] = [
                    'currency' => $c,
                    'recordKind' => $k->value,
                    'code' => 'import.manifest_total_variance',
                    'detail' => sprintf(
                        'Manifest declared %s %s for %s, staged records sum to %s %s',
                        $declaredMoney->amount(),
                        $c,
                        $k->value,
                        $stagedMoney->amount(),
                        $c,
                    ),
                ];
            }
        }

        ksort($buckets, SORT_STRING);
        $reconciliationBuckets = [];
        foreach ($buckets as $bucket) {
            $accounted = $this->math->add(
                $this->math->add($bucket['appliedTotal'], $bucket['queuedTotal']),
                $bucket['unanchoredTotal'],
            );
            $variance = $this->math->add(
                $bucket['expectedTotal'],
                new Money(-$accounted->minor, $accounted->currency),
            );
            $balanced = $variance->minor === 0;
            if (! $balanced) {
                $discrepancies[] = [
                    'currency' => $bucket['currency'],
                    'recordKind' => $bucket['recordKind']->value,
                    'code' => 'import.unaccounted_bucket_variance',
                    'detail' => sprintf(
                        'Bucket %s/%s has unaccounted variance %s %s',
                        $bucket['currency'],
                        $bucket['recordKind']->value,
                        $variance->amount(),
                        $bucket['currency'],
                    ),
                ];
            }
            $reconciliationBuckets[] = new ImportCurrencyReconciliation(
                sellerRef: $bucket['sellerRef'],
                currency: $bucket['currency'],
                recordKind: $bucket['recordKind'],
                expectedCount: $bucket['expectedCount'],
                expectedTotal: $bucket['expectedTotal'],
                appliedCount: $bucket['appliedCount'],
                appliedTotal: $bucket['appliedTotal'],
                queuedOrParkedCount: $bucket['queuedCount'],
                queuedOrParkedTotal: $bucket['queuedTotal'],
                unanchoredCount: $bucket['unanchoredCount'],
                unanchoredTotal: $bucket['unanchoredTotal'],
                variance: $variance,
                balanced: $balanced,
            );
        }

        $conservationMap = $monetary->readScopedConservation();
        $conservationSummary = [];
        foreach ($conservationMap as $currency => $conservation) {
            $conservationSummary[$currency] = $conservation->toSummary();
            if (! $conservation->conserved) {
                $discrepancies[] = [
                    'currency' => $currency,
                    'code' => 'import.ledger_conservation_violated',
                    'detail' => "Scoped ledger conservation equation violated for currency {$currency}",
                ];
            }
        }

        $externalActivationInputs = [
            [
                'inputId' => 'SourceFreezeCertificate',
                'status' => 'external_owner_supplied',
                'requiredForCutover' => true,
            ],
            [
                'inputId' => 'RetirementProof',
                'status' => 'external_owner_supplied',
                'requiredForCutover' => true,
            ],
            [
                'inputId' => 'ActivationCertificate',
                'status' => 'external_owner_supplied',
                'requiredForCutover' => true,
            ],
        ];

        $reconciled = $discrepancies === [] && $pendingQueuedOrParked === 0;
        $generatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $bodyWithoutDigest = [
            'schema' => ImportReconciliationReport::SCHEMA_V1,
            'installationRef' => $caller->installationRef,
            'environment' => $caller->environment,
            'sellerRef' => $caller->sellerRef,
            'batchId' => $batchId,
            'manifestDigest' => $manifestDigest,
            'batchStatus' => $batchStatus->value,
            'reconciled' => $reconciled,
            'buckets' => array_map(static fn (ImportCurrencyReconciliation $b): array => $b->toWire(), $reconciliationBuckets),
            'ledgerConservationByCurrency' => $conservationSummary,
            'accountingFacts' => $accountingFacts,
            'discrepancies' => $discrepancies,
            'provenance' => [
                'evidenceScope' => 'local_runtime_only',
                'externalActivationInputsSeparated' => true,
                'externalActivationInputs' => $externalActivationInputs,
            ],
            'generatedAt' => $generatedAt->format('Y-m-d\TH:i:s\Z'),
        ];
        $digest = $this->codec->digest($bodyWithoutDigest);

        return new ImportReconciliationReport(
            installationRef: $caller->installationRef,
            environment: $caller->environment,
            sellerRef: $caller->sellerRef,
            batchId: $batchId,
            manifestDigest: $manifestDigest,
            batchStatus: $batchStatus,
            reconciled: $reconciled,
            buckets: $reconciliationBuckets,
            ledgerConservationByCurrency: $conservationSummary,
            accountingFacts: $accountingFacts,
            discrepancies: $discrepancies,
            externalActivationInputs: $externalActivationInputs,
            digest: $digest,
            generatedAt: $generatedAt,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{sourceRecordId: string, sourceFactRef: string, factKind: string, currency: string, amountMinor: int, exportDigest: string}|null
     */
    private function evaluateAccountingFact(
        ImportSourceIdentity $identity,
        array $payload,
        Money $money,
        DateTimeImmutable $occurredAt,
        ImportObservationEnvelopeBuilder $envelopeBuilder,
    ): ?array {
        $funding = is_array($payload['fundingRefs'] ?? null) ? $payload['fundingRefs'] : [];
        $providerAccountRef = is_string($funding['providerAccountRef'] ?? null) ? trim($funding['providerAccountRef']) : '';
        $providerEffectRef = is_string($funding['providerOperationRef'] ?? null)
            ? trim($funding['providerOperationRef'])
            : (is_string($funding['originalOperationRef'] ?? null) ? trim($funding['originalOperationRef']) : $identity->sourceRecordId);
        if ($providerAccountRef === '' || $providerEffectRef === '') {
            return null;
        }
        if ($identity->recordKind === ImportRecordKind::ProvenRefundEvidence && ! is_string($funding['providerOperationRef'] ?? null)) {
            $providerEffectRef = 'rfnd:'.$identity->sourceRecordId;
        }

        $isRefund = $identity->recordKind === ImportRecordKind::ProvenRefundEvidence;
        $sourceLineage = $envelopeBuilder->sourceLineage(
            $identity->installationRef,
            $identity->sellerRef,
            $identity->environment,
            $identity->sourceInstallation,
            $identity->sourceBook,
        );

        $sourceRecord = new AccountingSourceRecord(
            schemaVersion: 'accounting.source.v1',
            eventType: $isRefund ? 'refund.succeeded.v1' : 'payment.captured.v1',
            installationRef: $identity->installationRef,
            sellerRef: $identity->sellerRef,
            environment: $identity->environment,
            sourceLineage: $sourceLineage,
            aggregateRef: $identity->sourceRecordId,
            aggregateRevision: 1,
            occurredAt: $occurredAt,
            payload: [
                'status' => 'confirmed',
                'providerAccountRef' => $providerAccountRef,
                'providerEffectRef' => $providerEffectRef,
                'actualMoney' => $money->toWire(),
            ],
        );

        $mapped = $this->accountingMapper->map($sourceRecord);
        if ($mapped->fact === null) {
            return null;
        }
        $finBridge = $this->accountingMapper->toFinBridge($mapped->fact);

        return [
            'sourceRecordId' => $identity->sourceRecordId,
            'sourceFactRef' => $finBridge->sourceFactRef,
            'factKind' => $finBridge->factKind->value,
            'currency' => $finBridge->currency,
            'amountMinor' => $finBridge->amountMinor,
            'exportDigest' => $finBridge->exportDigest,
        ];
    }

    /**
     * @param  array<mixed>  $funding
     * @param  array<mixed>  $origRefs
     */
    private function resolveOperationRefForRow(
        string $sourceRecordId,
        ImportRecordKind $recordKind,
        array $funding,
        array $origRefs,
    ): string {
        if ($recordKind === ImportRecordKind::ProvenRefundEvidence) {
            $candidate = (is_string($origRefs['refundRef'] ?? null) && trim($origRefs['refundRef']) !== '' ? trim($origRefs['refundRef']) : null)
                ?? (is_string($funding['providerOperationRef'] ?? null) && trim($funding['providerOperationRef']) !== '' ? trim($funding['providerOperationRef']) : null)
                ?? ('imp-rfnd:'.$sourceRecordId);

            return strlen($candidate) <= 200 ? $candidate : 'imp-rfnd:'.hash('sha256', $candidate);
        }

        $candidate = (is_string($funding['originalOperationRef'] ?? null) && trim($funding['originalOperationRef']) !== '' ? trim($funding['originalOperationRef']) : null)
            ?? (is_string($origRefs['paymentRef'] ?? null) && trim($origRefs['paymentRef']) !== '' ? trim($origRefs['paymentRef']) : null)
            ?? (is_string($funding['providerOperationRef'] ?? null) && trim($funding['providerOperationRef']) !== '' ? trim($funding['providerOperationRef']) : null)
            ?? ('imp-pay:'.$sourceRecordId);

        return strlen($candidate) <= 200 ? $candidate : 'imp-pay:'.hash('sha256', $candidate);
    }

    /**
     * Resolves the revision mapping target and the applied receipt status for a chunk of staged rows in two
     * set-based round trips. Lookup keys mirror ImportIdentityStore::findMapping() with a revision and
     * ImportIdentityStore::findAppliedReceipt(); results are keyed by the row position inside the chunk.
     *
     * @param  list<object>  $chunk
     * @return array<int, array{mappingTargetRef: ?string, receiptStatus: ?ImportReceiptStatus}>
     */
    private function resolveChunk(Connection $db, array $chunk): array
    {
        $mappingKeys = [];
        $receiptKeys = [];
        foreach ($chunk as $position => $row) {
            $identity = new ImportSourceIdentity(
                installationRef: self::strField($row, 'installation_ref'),
                environment: self::strField($row, 'environment'),
                sellerRef: self::strField($row, 'seller_ref'),
                sourceSystem: self::strField($row, 'source_system'),
                sourceInstallation: self::strField($row, 'source_installation'),
                sourceBook: self::strField($row, 'source_book'),
                sourceRecordId: self::strField($row, 'source_record_id'),
                recordKind: ImportRecordKind::from(self::strField($row, 'record_kind')),
            );
            $revision = self::intField($row, 'source_record_revision');
            $mappingKeys[] = [
                'ord' => $position,
                'installation_ref' => $identity->installationRef,
                'environment' => $identity->environment,
                'seller_ref' => $identity->sellerRef,
                'source_installation' => $identity->sourceInstallation,
                'source_book' => $identity->sourceBook,
                'source_record_id' => $identity->sourceRecordId,
                'record_kind' => $identity->recordKind->value,
                'source_record_revision' => $revision,
            ];
            $receiptKeys[] = [
                'ord' => $position,
                'installation_ref' => $identity->installationRef,
                'environment' => $identity->environment,
                'seller_ref' => $identity->sellerRef,
                'source_identity_digest' => $identity->identityDigest($this->codec),
                'source_record_revision' => $revision,
            ];
        }
        if ($mappingKeys === []) {
            return [];
        }

        $mappings = $db->select(
            'SELECT k.ord, m.billium_target_ref '
            .'FROM jsonb_to_recordset(?::jsonb) AS k('
            .'ord integer, installation_ref text, environment text, seller_ref text, source_installation text, '
            .'source_book text, source_record_id text, record_kind text, source_record_revision bigint) '
            .'JOIN public.billium_import_mapping m '
            .'ON m.installation_ref = k.installation_ref AND m.environment = k.environment AND m.seller_ref = k.seller_ref '
            .'AND m.source_installation = k.source_installation AND m.source_book = k.source_book '
            .'AND m.source_record_id = k.source_record_id AND m.record_kind = k.record_kind '
            .'AND m.source_record_revision = k.source_record_revision',
            [json_encode($mappingKeys, JSON_THROW_ON_ERROR)],
        );
        /** @var array<int, ?string> $targetRefs */
        $targetRefs = [];
        foreach ($mappings as $mapping) {
            $targetRefs[self::intField($mapping, 'ord')] = self::optStrField($mapping, 'billium_target_ref');
        }

        $receipts = $db->select(
            'SELECT DISTINCT ON (k.ord) k.ord, r.status '
            .'FROM jsonb_to_recordset(?::jsonb) AS k('
            .'ord integer, installation_ref text, environment text, seller_ref text, '
            .'source_identity_digest text, source_record_revision bigint) '
            .'JOIN public.billium_import_receipt r '
            .'ON r.installation_ref = k.installation_ref AND r.environment = k.environment AND r.seller_ref = k.seller_ref '
            .'AND r.source_identity_digest = k.source_identity_digest AND r.source_record_revision = k.source_record_revision '
            ."AND r.status IN ('applied', 'observation_queued') "
            .'ORDER BY k.ord, r.id ASC',
            [json_encode($receiptKeys, JSON_THROW_ON_ERROR)],
        );
        /** @var array<int, ImportReceiptStatus> $receiptStatuses */
        $receiptStatuses = [];
        foreach ($receipts as $receipt) {
            $receiptStatuses[self::intField($receipt, 'ord')] = ImportReceiptStatus::from(self::strField($receipt, 'status'));
        }

        $resolved = [];
        foreach (array_keys($chunk) as $position) {
            $resolved[$position] = [
                'mappingTargetRef' => $targetRefs[$position] ?? null,
                'receiptStatus' => $receiptStatuses[$position] ?? null,
            ];
        }

        return $resolved;
    }

    private function connection(): Connection
    {
        return $this->databases->connection($this->options->connection);
    }

    /** @return array<string, mixed> */
    private static function decodeArray(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            return [];
        }
        $typed = [];
        foreach ($decoded as $k => $v) {
            $typed[(string) $k] = $v;
        }

        return $typed;
    }

    private static function strField(mixed $row, string $column): string
    {
        $value = is_object($row) ? (get_object_vars($row)[$column] ?? null) : null;
        if (! is_string($value)) {
            throw new LogicException("Expected string column {$column}");
        }

        return $value;
    }

    private static function optStrField(mixed $row, string $column): ?string
    {
        $value = is_object($row) ? (get_object_vars($row)[$column] ?? null) : null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new LogicException("Expected nullable string column {$column}");
        }

        return $value;
    }

    private static function intField(mixed $row, string $column): int
    {
        $value = is_object($row) ? (get_object_vars($row)[$column] ?? null) : null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            return (int) $value;
        }

        throw new LogicException("Expected integer column {$column}");
    }

    private static function optIntField(mixed $row, string $column): ?int
    {
        $value = is_object($row) ? (get_object_vars($row)[$column] ?? null) : null;
        if ($value === null) {
            return null;
        }

        return self::intField($row, $column);
    }
}
