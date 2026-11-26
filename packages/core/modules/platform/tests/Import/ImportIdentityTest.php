<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Import;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Envelopes\ExternalImport;
use Billium\Contracts\Data\Envelopes\ExternalImportManifest;
use Billium\Contracts\Data\Envelopes\ExternalImportPreview;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Contracts\Import\ImportIdentityStore;
use Billium\Platform\Contracts\Import\ImportPreviewEvaluator;
use Billium\Platform\Data\Import\ImportBatchStatus;
use Billium\Platform\Data\Import\ImportCurrencyTotal;
use Billium\Platform\Data\Import\ImportEvidenceRef;
use Billium\Platform\Data\Import\ImportManifest;
use Billium\Platform\Data\Import\ImportMappingRegistrationResult;
use Billium\Platform\Data\Import\ImportMappingStatus;
use Billium\Platform\Data\Import\ImportReceiptStatus;
use Billium\Platform\Data\Import\ImportRecordInput;
use Billium\Platform\Data\Import\ImportRecordKind;
use Billium\Platform\Data\Import\ImportSourceIdentity;
use Billium\Platform\Tests\PlatformTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;

#[Group('P9.1')]
#[Group('D71')]
#[Group('pg')]
final class ImportIdentityTest extends PlatformTestCase
{
    private const string DIGEST_A = 'sha256:1111111111111111111111111111111111111111111111111111111111111111';

    private const string DIGEST_B = 'sha256:2222222222222222222222222222222222222222222222222222222222222222';

    private const string DIGEST_C = 'sha256:3333333333333333333333333333333333333333333333333333333333333333';

    private const string DIGEST_D = 'sha256:4444444444444444444444444444444444444444444444444444444444444444';

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeSession();
    }

    public function test_seller_and_environment_isolation_with_cross_seller_fk_guard(): void
    {
        $store = $this->importStore();

        $sellerA = $this->identity(sellerRef: 'seller-a', environment: 'test', sourceRecordId: 'obl-100');
        $sellerB = $this->identity(sellerRef: 'seller-b', environment: 'test', sourceRecordId: 'obl-100');
        $sellerAProd = $this->identity(sellerRef: 'seller-a', environment: 'prod', sourceRecordId: 'obl-100');

        self::assertNotSame($sellerA->identityDigest(), $sellerB->identityDigest());
        self::assertNotSame($sellerA->identityDigest(), $sellerAProd->identityDigest());
        self::assertNotSame($sellerA->stableSourceFactRef(1), $sellerB->stableSourceFactRef(1));

        $regA = $store->registerMapping($sellerA, 1, self::DIGEST_A, $sellerA->stableSourceFactRef(1));
        $regB = $store->registerMapping($sellerB, 1, self::DIGEST_A, $sellerB->stableSourceFactRef(1));
        $regAProd = $store->registerMapping($sellerAProd, 1, self::DIGEST_A, $sellerAProd->stableSourceFactRef(1));

        self::assertSame(ImportMappingRegistrationResult::CREATED, $regA->outcome);
        self::assertSame(ImportMappingRegistrationResult::CREATED, $regB->outcome);
        self::assertSame(ImportMappingRegistrationResult::CREATED, $regAProd->outcome);
        self::assertNotSame($regA->mapping->mappingRef, $regB->mapping->mappingRef);

        // Cross-seller record insertion into seller-a's batch must fail the composite FK at PG level.
        $batchPk = $store->stageBatch($this->manifest('batch-a', 'seller-a'));
        $ownerPdo = TestEnvironment::pdo(owner: true);
        $sqlState = self::sqlState(
            $ownerPdo,
            "INSERT INTO public.billium_import_record (
                batch_pk, installation_ref, environment, seller_ref, source_system, source_installation,
                source_book, source_record_id, source_record_revision, record_kind, source_identity_digest,
                payload_digest, event_business_date, validation_status, payload
            ) VALUES (
                {$batchPk}, 'inst-test', 'test', 'seller-b', 'host-commerce', 'src-inst-1',
                'book-main', 'obl-100', 1, 'proven_obligation', '".self::DIGEST_A."',
                '".self::DIGEST_A."', '2026-10-01T00:00:00Z', 'ready', '{}'::jsonb
            )",
        );
        self::assertSame('23503', $sqlState);
    }

    public function test_same_revision_same_digest_replays_while_different_digest_conflicts_and_batch_is_not_identity(): void
    {
        $store = $this->importStore();
        $identity = $this->identity(sellerRef: 'seller-a', environment: 'test', sourceRecordId: 'obl-200');
        $factRef = $identity->stableSourceFactRef(1);

        $first = $store->registerMapping(
            identity: $identity,
            sourceRecordRevision: 1,
            payloadDigest: self::DIGEST_A,
            sourceFactRef: $factRef,
            importPolicyVersion: 'policy-v1',
        );
        self::assertSame(ImportMappingRegistrationResult::CREATED, $first->outcome);

        // Repackaged into another batch / policy version with identical identity, revision and digest => duplicate, same factRef.
        $repackaged = $store->registerMapping(
            identity: $identity,
            sourceRecordRevision: 1,
            payloadDigest: self::DIGEST_A,
            sourceFactRef: $factRef,
            importPolicyVersion: 'policy-v2',
        );
        self::assertSame(ImportMappingRegistrationResult::DUPLICATE, $repackaged->outcome);
        self::assertSame($first->mapping->id, $repackaged->mapping->id);
        self::assertSame($first->mapping->mappingRef, $repackaged->mapping->mappingRef);
        self::assertSame($factRef, $repackaged->mapping->sourceFactRef);

        // Same identity + revision with a different payload digest => conflict without mutating the original row.
        $conflict = $store->registerMapping(
            identity: $identity,
            sourceRecordRevision: 1,
            payloadDigest: self::DIGEST_B,
            sourceFactRef: $factRef,
            importPolicyVersion: 'policy-v2',
        );
        self::assertSame(ImportMappingRegistrationResult::CONFLICT, $conflict->outcome);
        self::assertSame('payload_digest_mismatch', $conflict->conflictReason);
        self::assertSame(self::DIGEST_A, $conflict->mapping->payloadDigest);

        // Raw SQL duplicate insert for same (seller, source_installation, source_book, source_record_id, record_kind, rev) fails unique constraint.
        $ownerPdo = TestEnvironment::pdo(owner: true);
        $sqlState = self::sqlState(
            $ownerPdo,
            "INSERT INTO public.billium_import_mapping (
                mapping_ref, installation_ref, environment, seller_ref, source_system, source_installation,
                source_book, source_record_id, record_kind, source_record_revision, source_identity_digest,
                payload_digest, source_fact_ref, status
            ) VALUES (
                'imp-map:raw-dup', 'inst-test', 'test', 'seller-a', 'host-commerce', 'src-inst-1',
                'book-main', 'obl-200', 'proven_obligation', 1, '".$identity->identityDigest()."',
                '".self::DIGEST_B."', 'import:raw-dup', 'active'
            )",
        );
        self::assertSame('23505', $sqlState);
    }

    public function test_correction_supersedes_without_overwrite_and_preserves_historical_legal_number(): void
    {
        $store = $this->importStore();
        $identity = $this->identity(
            sellerRef: 'seller-a',
            environment: 'test',
            sourceRecordId: 'inv-500',
            recordKind: ImportRecordKind::ProvenInvoiceHistory,
        );

        $rev1 = $store->registerMapping(
            identity: $identity,
            sourceRecordRevision: 1,
            payloadDigest: self::DIGEST_A,
            sourceFactRef: $identity->stableSourceFactRef(1),
            originalIssuer: 'issuer-legacy-free-org',
            originalSourceNumber: 'INV-2026-000500',
        );
        self::assertSame(ImportMappingRegistrationResult::CREATED, $rev1->outcome);
        self::assertSame('issuer-legacy-free-org', $rev1->mapping->originalIssuer);
        self::assertSame('INV-2026-000500', $rev1->mapping->originalSourceNumber);

        // Raw SQL cannot rename source_book, source_record_id, payload_digest or original_source_number.
        $ownerPdo = TestEnvironment::pdo(owner: true);
        self::assertSame(
            '55000',
            self::sqlState($ownerPdo, "UPDATE public.billium_import_mapping SET source_book = 'book-renamed' WHERE id = {$rev1->mapping->id}"),
        );
        self::assertSame(
            '55000',
            self::sqlState($ownerPdo, "UPDATE public.billium_import_mapping SET original_source_number = 'BILLIUM-1' WHERE id = {$rev1->mapping->id}"),
        );

        // Correction creates revision 2 and marks revision 1 superseded without deleting or overwriting rev 1.
        $rev2 = $store->supersedeMapping(
            identity: $identity,
            previousRevision: 1,
            newRevision: 2,
            newPayloadDigest: self::DIGEST_B,
            newSourceFactRef: $identity->stableSourceFactRef(2),
            originalIssuer: 'issuer-legacy-free-org',
            originalSourceNumber: 'INV-2026-000500',
        );
        self::assertSame(2, $rev2->sourceRecordRevision);
        self::assertSame(ImportMappingStatus::Active, $rev2->status);
        self::assertNotSame($rev1->mapping->sourceFactRef, $rev2->sourceFactRef);

        $persistedRev1 = $store->findMapping($identity, 1);
        self::assertNotNull($persistedRev1);
        self::assertSame(ImportMappingStatus::Superseded, $persistedRev1->status);
        self::assertSame(2, $persistedRev1->supersededByRevision);
    }

    public function test_tombstones_are_immutable_undeletable_and_block_silent_reactivation(): void
    {
        $store = $this->importStore();
        $identity = $this->identity(sellerRef: 'seller-a', environment: 'test', sourceRecordId: 'obl-tomb-1');

        $created = $store->registerMapping($identity, 1, self::DIGEST_A, $identity->stableSourceFactRef(1));
        $tombstoned = $store->tombstoneMapping($identity, 1, 'remediated_via_credit_note');
        self::assertSame(ImportMappingStatus::Tombstoned, $tombstoned->status);
        self::assertSame('remediated_via_credit_note', $tombstoned->tombstoneReason);
        self::assertNotNull($tombstoned->tombstonedAt);

        // Attempting to register again (even at revision 2) returns tombstoned_conflict.
        $retry = $store->registerMapping($identity, 2, self::DIGEST_B, $identity->stableSourceFactRef(2));
        self::assertSame(ImportMappingRegistrationResult::TOMBSTONED_CONFLICT, $retry->outcome);
        self::assertSame('source_identity_tombstoned', $retry->conflictReason);

        // Raw SQL cannot delete, un-tombstone or re-insert an active mapping for a tombstoned identity.
        $ownerPdo = TestEnvironment::pdo(owner: true);
        self::assertSame(
            '55000',
            self::sqlState($ownerPdo, "DELETE FROM public.billium_import_mapping WHERE id = {$created->mapping->id}"),
        );
        self::assertSame(
            '55000',
            self::sqlState($ownerPdo, "UPDATE public.billium_import_mapping SET status = 'active', tombstone_reason = NULL, tombstoned_at = NULL WHERE id = {$created->mapping->id}"),
        );
        self::assertSame(
            '55000',
            self::sqlState(
                $ownerPdo,
                "INSERT INTO public.billium_import_mapping (
                    mapping_ref, installation_ref, environment, seller_ref, source_system, source_installation,
                    source_book, source_record_id, record_kind, source_record_revision, source_identity_digest,
                    payload_digest, source_fact_ref, status
                ) VALUES (
                    'imp-map:reactivate', 'inst-test', 'test', 'seller-a', 'host-commerce', 'src-inst-1',
                    'book-main', 'obl-tomb-1', 'proven_obligation', 2, '".$identity->identityDigest()."',
                    '".self::DIGEST_B."', 'import:reactivate', 'active'
                )",
            ),
        );
    }

    public function test_manifest_immutability_batch_lifecycle_and_append_only_applied_once_receipts(): void
    {
        $store = $this->importStore();
        $manifest = $this->manifest('batch-life-1', 'seller-a');
        $batchPk = $store->stageBatch($manifest);

        // Idempotent stage with same manifest returns same batch PK.
        self::assertSame($batchPk, $store->stageBatch($manifest));

        // Same batchId with different manifestDigest conflicts.
        try {
            $store->stageBatch($this->manifest('batch-life-1', 'seller-a', manifestDigest: self::DIGEST_D));
            self::fail('expected conflict when re-staging same batchId with different manifestDigest');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::IdempotencyConflict, $exception->problem);
        }

        // Manifest checksum & cutoff are immutable in PostgreSQL.
        $ownerPdo = TestEnvironment::pdo(owner: true);
        self::assertSame(
            '55000',
            self::sqlState($ownerPdo, "UPDATE public.billium_import_batch SET manifest_digest = '".self::DIGEST_D."' WHERE id = {$batchPk}"),
        );
        self::assertSame(
            '55000',
            self::sqlState($ownerPdo, "UPDATE public.billium_import_batch SET export_cutoff = '2026-10-16T00:00:00Z' WHERE id = {$batchPk}"),
        );

        // Valid lifecycle: staged -> validated -> approved -> applying -> reconciled -> completed.
        $store->transitionBatch($batchPk, ImportBatchStatus::Validated);
        $store->transitionBatch($batchPk, ImportBatchStatus::Approved, approvalRef: 'approval-1', authorityFenceRef: 'fence-1');
        $store->transitionBatch($batchPk, ImportBatchStatus::Applying);
        // Once applying started, rollback to rejected is forbidden at PG guard level.
        self::assertSame(
            '55000',
            self::sqlState($ownerPdo, "UPDATE public.billium_import_batch SET status = 'rejected' WHERE id = {$batchPk}"),
        );
        $store->transitionBatch($batchPk, ImportBatchStatus::Reconciled);
        $store->transitionBatch($batchPk, ImportBatchStatus::Completed);
        self::assertSame(
            '55000',
            self::sqlState($ownerPdo, "UPDATE public.billium_import_batch SET status = 'staged' WHERE id = {$batchPk}"),
        );

        // Append-only receipts & applied-once guarantee.
        $identity = $this->identity(sellerRef: 'seller-a', environment: 'test', sourceRecordId: 'obl-rcpt-1');
        $mapping = $store->registerMapping($identity, 1, self::DIGEST_A, $identity->stableSourceFactRef(1))->mapping;
        $receipt = $store->recordReceipt(
            batchPk: $batchPk,
            receiptRef: 'imp-rcpt-1',
            identity: $identity,
            sourceRecordRevision: 1,
            payloadDigest: self::DIGEST_A,
            status: ImportReceiptStatus::Applied,
            mappingPk: $mapping->id,
            sourceFactRef: $mapping->sourceFactRef,
            billiumReceiptRef: 'ledger-rcpt-1',
        );
        self::assertSame(ImportReceiptStatus::Applied, $receipt->status);
        self::assertSame('55000', self::sqlState($ownerPdo, "UPDATE public.billium_import_receipt SET status = 'rejected' WHERE id = {$receipt->id}"));
        self::assertSame('55000', self::sqlState($ownerPdo, "DELETE FROM public.billium_import_receipt WHERE id = {$receipt->id}"));

        // Second applied/observation_queued receipt for same identity+revision violates unique partial index (23505).
        self::assertSame(
            '23505',
            self::sqlState(
                $ownerPdo,
                "INSERT INTO public.billium_import_receipt (
                    receipt_ref, installation_ref, environment, seller_ref, batch_pk, mapping_pk,
                    source_identity_digest, source_record_revision, payload_digest, status
                ) VALUES (
                    'imp-rcpt-2', 'inst-test', 'test', 'seller-a', {$batchPk}, {$mapping->id},
                    '".$identity->identityDigest()."', 1, '".self::DIGEST_A."', 'applied'
                )",
            ),
        );
    }

    public function test_preview_evaluator_classifies_all_outcomes_separates_currencies_and_roundtrips_codec(): void
    {
        $store = $this->importStore();
        $evaluator = $this->previewEvaluator();
        $codec = new Codec;
        $cutoff = new DateTimeImmutable('2026-10-15T00:00:00Z');

        $manifest = $this->manifest('batch-preview-1', 'seller-a');
        $batchPk = $store->stageBatch($manifest);

        // Pre-seed one already_applied record and one conflicting mapping.
        $appliedId = $this->identity('seller-a', 'test', 'obl-already');
        $appliedMap = $store->registerMapping($appliedId, 1, self::DIGEST_A, $appliedId->stableSourceFactRef(1))->mapping;
        $store->recordReceipt(
            batchPk: $batchPk,
            receiptRef: 'imp-rcpt-applied-1',
            identity: $appliedId,
            sourceRecordRevision: 1,
            payloadDigest: self::DIGEST_A,
            status: ImportReceiptStatus::Applied,
            mappingPk: $appliedMap->id,
            sourceFactRef: $appliedMap->sourceFactRef,
            billiumReceiptRef: 'ledger-rcpt-applied-1',
        );

        $conflictId = $this->identity('seller-a', 'test', 'obl-conflict');
        $store->registerMapping($conflictId, 1, self::DIGEST_A, $conflictId->stableSourceFactRef(1));

        $records = [
            // 1. Ready RUB proven obligation.
            new ImportRecordInput(
                identity: $this->identity('seller-a', 'test', 'obl-rub-1'),
                batchId: $manifest->batchId,
                sourceExportRevision: 1,
                exportCutoff: $cutoff,
                manifestDigest: $manifest->manifestDigest,
                sourceRecordRevision: 1,
                eventBusinessDate: $cutoff,
                payloadDigest: self::DIGEST_A,
                evidenceRefs: [new ImportEvidenceRef('ev-price-1', 'price_snapshot', self::DIGEST_A, true)],
                money: new Money(149000, Currency::of('RUB')),
                frozenPriceSnapshot: ['priceVersionRef' => 'pv-1', 'priceDigest' => self::DIGEST_A, 'currency' => 'RUB'],
            ),
            // 2. Ready USD proven obligation (must stay separate from RUB!).
            new ImportRecordInput(
                identity: $this->identity('seller-a', 'test', 'obl-usd-1'),
                batchId: $manifest->batchId,
                sourceExportRevision: 1,
                exportCutoff: $cutoff,
                manifestDigest: $manifest->manifestDigest,
                sourceRecordRevision: 1,
                eventBusinessDate: $cutoff,
                payloadDigest: self::DIGEST_B,
                evidenceRefs: [new ImportEvidenceRef('ev-price-2', 'price_snapshot', self::DIGEST_B, true)],
                money: new Money(2500, Currency::of('USD')),
                frozenPriceSnapshot: ['priceVersionRef' => 'pv-2', 'priceDigest' => self::DIGEST_B, 'currency' => 'USD'],
            ),
            // 3. Unanchored evidence (no invented money/price/currency).
            new ImportRecordInput(
                identity: $this->identity('seller-a', 'test', 'ent-unanchored-1', ImportRecordKind::UnanchoredEvidence),
                batchId: $manifest->batchId,
                sourceExportRevision: 1,
                exportCutoff: $cutoff,
                manifestDigest: $manifest->manifestDigest,
                sourceRecordRevision: 1,
                eventBusinessDate: $cutoff,
                payloadDigest: self::DIGEST_C,
                missingEvidence: ['frozenPriceSnapshot', 'money', 'fundingRefs'],
            ),
            // 4. Rejected cross-seller record.
            new ImportRecordInput(
                identity: $this->identity('seller-other', 'test', 'obl-bad-scope'),
                batchId: $manifest->batchId,
                sourceExportRevision: 1,
                exportCutoff: $cutoff,
                manifestDigest: $manifest->manifestDigest,
                sourceRecordRevision: 1,
                eventBusinessDate: $cutoff,
                payloadDigest: self::DIGEST_A,
                money: new Money(5000, Currency::of('RUB')),
                frozenPriceSnapshot: ['priceVersionRef' => 'pv-1', 'priceDigest' => self::DIGEST_A, 'currency' => 'RUB'],
            ),
            // 5. Conflict (same identity & revision, different payloadDigest).
            new ImportRecordInput(
                identity: $conflictId,
                batchId: $manifest->batchId,
                sourceExportRevision: 1,
                exportCutoff: $cutoff,
                manifestDigest: $manifest->manifestDigest,
                sourceRecordRevision: 1,
                eventBusinessDate: $cutoff,
                payloadDigest: self::DIGEST_B,
                evidenceRefs: [new ImportEvidenceRef('ev-c', 'price_snapshot', self::DIGEST_B, true)],
                money: new Money(10000, Currency::of('RUB')),
                frozenPriceSnapshot: ['priceVersionRef' => 'pv-1', 'priceDigest' => self::DIGEST_B, 'currency' => 'RUB'],
            ),
            // 6. Already applied.
            new ImportRecordInput(
                identity: $appliedId,
                batchId: $manifest->batchId,
                sourceExportRevision: 1,
                exportCutoff: $cutoff,
                manifestDigest: $manifest->manifestDigest,
                sourceRecordRevision: 1,
                eventBusinessDate: $cutoff,
                payloadDigest: self::DIGEST_A,
                evidenceRefs: [new ImportEvidenceRef('ev-a', 'price_snapshot', self::DIGEST_A, true)],
                money: new Money(20000, Currency::of('RUB')),
                frozenPriceSnapshot: ['priceVersionRef' => 'pv-1', 'priceDigest' => self::DIGEST_A, 'currency' => 'RUB'],
            ),
        ];

        $report = $evaluator->evaluate($manifest, $records);
        self::assertSame([
            'total' => 6,
            'ready' => 2,
            'unanchored_evidence' => 1,
            'rejected' => 1,
            'conflict' => 1,
            'already_applied' => 1,
        ], $report->counts);
        self::assertSame(ImportBatchStatus::Quarantined, $report->batchStatus);

        // Mixed currencies kept strictly separate with authoritativeCurrentBalance = false.
        $byCurrency = [];
        foreach ($report->totalsByCurrency as $total) {
            self::assertFalse($total->authoritativeCurrentBalance);
            $byCurrency[$total->currency] = $total->totalAmount->amount();
        }
        self::assertSame('1790.00', $byCurrency['RUB']);
        self::assertSame('25.00', $byCurrency['USD']);
        self::assertContains('ent-unanchored-1', $report->referencesWithoutEvidence);

        // Preview report wire format decodes cleanly through the public Billium Contracts Codec!
        $decodedPreview = $codec->decode(json_encode($report->toWire(), JSON_THROW_ON_ERROR));
        self::assertInstanceOf(ExternalImportPreview::class, $decodedPreview);
        self::assertSame(ExternalImportPreview::SCHEMA, $decodedPreview->schema);

        // Manifest & external.import.v1 envelopes also decode via Codec.
        $manifestWire = $manifest->toWire();
        unset($manifestWire['digest']);
        $manifestWire['digest'] = $codec->digest($manifestWire);
        self::assertInstanceOf(ExternalImportManifest::class, $codec->decode(json_encode($manifestWire, JSON_THROW_ON_ERROR)));

        $recordWire = [
            'schema' => ExternalImport::SCHEMA,
            'batchId' => 'batch-preview-1',
            'sourceSystem' => 'host-commerce',
            'sourceInstallation' => 'src-inst-1',
            'sourceBook' => 'book-main',
            'sourceExportRevision' => 1,
            'exportCutoff' => '2026-10-15T00:00:00Z',
            'manifestDigest' => $manifestWire['digest'],
            'installationRef' => self::INSTALLATION,
            'environment' => 'test',
            'sellerRef' => 'seller-a',
            'sourceRecordId' => 'ent-unanchored-1',
            'sourceRecordRevision' => 1,
            'recordKind' => ImportRecordKind::UnanchoredEvidence->value,
            'originalBusinessRefs' => ['agreementRef' => 'agr-1'],
            'eventBusinessDate' => '2026-10-14T12:00:00Z',
            'evidenceRefs' => [],
            'missingEvidence' => ['frozenPriceSnapshot', 'money'],
        ];
        $recordWire['digest'] = $codec->digest($recordWire);
        $decodedRecord = $codec->decode(json_encode($recordWire, JSON_THROW_ON_ERROR));
        self::assertInstanceOf(ExternalImport::class, $decodedRecord);
        self::assertNull($decodedRecord->money);
    }

    private function importStore(): ImportIdentityStore
    {
        return $this->application()->make(ImportIdentityStore::class);
    }

    private function previewEvaluator(): ImportPreviewEvaluator
    {
        return $this->application()->make(ImportPreviewEvaluator::class);
    }

    private function identity(
        string $sellerRef,
        string $environment,
        string $sourceRecordId,
        ImportRecordKind $recordKind = ImportRecordKind::ProvenObligation,
    ): ImportSourceIdentity {
        return new ImportSourceIdentity(
            installationRef: self::INSTALLATION,
            environment: $environment,
            sellerRef: $sellerRef,
            sourceSystem: 'host-commerce',
            sourceInstallation: 'src-inst-1',
            sourceBook: 'book-main',
            sourceRecordId: $sourceRecordId,
            recordKind: $recordKind,
        );
    }

    private function manifest(
        string $batchId,
        string $sellerRef,
        string $manifestDigest = self::DIGEST_A,
    ): ImportManifest {
        return new ImportManifest(
            batchId: $batchId,
            installationRef: self::INSTALLATION,
            environment: 'test',
            sellerRef: $sellerRef,
            sourceSystem: 'host-commerce',
            sourceInstallation: 'src-inst-1',
            sourceBook: 'book-main',
            sourceExportRevision: 1,
            exportCutoff: new DateTimeImmutable('2026-10-15T00:00:00Z'),
            recordCount: 1,
            recordsChecksum: self::DIGEST_B,
            manifestDigest: $manifestDigest,
            sanitizationPolicy: [
                'piiRedacted' => true,
                'secretsStripped' => true,
                'policyVersion' => 'sanitize-v1',
            ],
            totalsByCurrency: [
                new ImportCurrencyTotal(
                    sellerRef: $sellerRef,
                    currency: 'RUB',
                    recordKind: ImportRecordKind::ProvenObligation,
                    count: 1,
                    totalAmount: new Money(149000, Currency::of('RUB')),
                ),
            ],
        );
    }
}
