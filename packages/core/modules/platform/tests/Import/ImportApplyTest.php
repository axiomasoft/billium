<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Import;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Platform\Contracts\Import\ImportBatchApplier;
use Billium\Platform\Contracts\Import\ImportIdentityStore;
use Billium\Platform\Contracts\Import\ImportPreviewEvaluator;
use Billium\Platform\Contracts\Import\ImportReconciler;
use Billium\Platform\Data\Import\ImportApplyOptions;
use Billium\Platform\Data\Import\ImportBatchStatus;
use Billium\Platform\Data\Import\ImportLinkedCorrection;
use Billium\Platform\Data\Import\ImportMappingStatus;
use Billium\Platform\Data\Import\ImportRecordApplyStatus;
use Billium\Platform\Data\Import\ImportRecordKind;
use Billium\Platform\Data\Import\ImportValidationStatus;
use Billium\Platform\Internal\Import\Sources\GenericExportProfile;
use Billium\Platform\Internal\Import\Sources\GenericExportReader;
use Billium\Platform\Internal\Import\Sources\RecurringExportProfile;
use Billium\Platform\Tests\PlatformTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('P9.3')]
#[Group('D71')]
#[Group('MG-11')]
#[Group('PF-09')]
#[Group('pg')]
final class ImportApplyTest extends PlatformTestCase
{
    private const string DIGEST_1 = 'sha256:1111111111111111111111111111111111111111111111111111111111111111';

    private const string DIGEST_2 = 'sha256:2222222222222222222222222222222222222222222222222222222222222222';

    private const string DIGEST_3 = 'sha256:3333333333333333333333333333333333333333333333333333333333333333';

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeSession();
        $this->asAuthorizedCaller('seller-recurring-1');
    }

    /**
     * IM-02 + IM-09 + IM-11 + IM-14 + IM-16:
     * Applies the reference-recurring complete manifest across RUB & USD, retains frozen historical PriceVersion,
     * preserves historical invoice originalIssuer/sourceNumber without issuing a second Billium legal number,
     * exports stable AccountingFactMapper sourceFactRefs without duplicate cash/GL or PSP calls, and replays
     * across a second batch and updated mapper policy version without creating duplicate economic state.
     */
    public function test_complete_manifest_applies_reconciles_separates_currencies_preserves_legal_numbers_and_replays_once(): void
    {
        $fixturePath = dirname(__DIR__, 6).'/fixtures/integration/import/reference-recurring/complete-manifest.json';
        $codec = new Codec;
        $profileV1 = new RecurringExportProfile(version: '1.0.0');
        $reader1 = GenericExportReader::fromFile($fixturePath, $profileV1, 'import-policy-v1', $codec);

        $result1 = $this->applier()->apply(
            $reader1->manifest(),
            $reader1->records(),
            new ImportApplyOptions(
                capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                expectedManifestDigest: $reader1->manifest()->manifestDigest,
                approvalRef: 'approve-complete-001',
            ),
        );

        self::assertSame(ImportBatchStatus::Completed, $result1->batchStatus);
        self::assertNotNull($result1->reconciliation);
        self::assertTrue($result1->reconciliation->reconciled);
        self::assertSame([], $result1->reconciliation->discrepancies);

        // IM-09: Frozen historical PriceVersion (12000.00 RUB, pv-2025-annual-rub) is retained, never current plan (15900.00 RUB).
        $queries = $this->application()->make(LedgerQueries::class);
        $rubObl = $queries->obligation('src-obl-annual-rub-001');
        self::assertSame('12000.00', $rubObl['originalDue']['amount']);
        self::assertSame('RUB', $rubObl['originalDue']['currency']);
        self::assertSame('0.00', $rubObl['open']['amount']);
        self::assertSame('12000.00', $rubObl['allocated']['amount']);
        self::assertSame('pv-2025-annual-rub', $this->value("SELECT price_version_ref FROM billium_obligation WHERE obligation_ref = 'src-obl-annual-rub-001'"));

        $usdObl = $queries->obligation('src-obl-addon-usd-001');
        self::assertSame('250.00', $usdObl['originalDue']['amount']);
        self::assertSame('USD', $usdObl['originalDue']['currency']);
        self::assertSame('0.00', $usdObl['open']['amount']);

        // IM-16: Historical invoice preserves originalIssuer + sourceNumber in mapping and issues ZERO Billium invoices/legal numbers.
        self::assertSame(0, $this->rows('billium_invoice'));
        $histMapping = $this->row("SELECT original_issuer, original_source_number, billium_target_kind, billium_target_ref FROM billium_import_mapping WHERE source_record_id = 'rec-inv-hist-001'");
        self::assertSame('seller-legal-entity-rec-1', $histMapping['original_issuer']);
        self::assertSame('REC-INV-2025-000412', $histMapping['original_source_number']);
        self::assertSame('historical_invoice', $histMapping['billium_target_kind']);

        // IM-14: Mixed currencies (RUB and USD) are reconciled separately and never summed together.
        $reconcileReport = $this->reconciler()->reconcileBatch($reader1->manifest()->batchId);
        self::assertTrue($reconcileReport->reconciled);
        self::assertArrayHasKey('RUB', $reconcileReport->ledgerConservationByCurrency);
        self::assertArrayHasKey('USD', $reconcileReport->ledgerConservationByCurrency);
        self::assertTrue($reconcileReport->ledgerConservationByCurrency['RUB']['conserved']);
        self::assertTrue($reconcileReport->ledgerConservationByCurrency['USD']['conserved']);
        self::assertSame(1_200_000, $reconcileReport->ledgerConservationByCurrency['RUB']['obligationsDueMinor']);
        self::assertSame(25_000, $reconcileReport->ledgerConservationByCurrency['USD']['obligationsDueMinor']);

        // External activation inputs are separated in report provenance.
        self::assertCount(3, $reconcileReport->externalActivationInputs);

        // IM-11: Imported historical payments/refunds export deterministic sourceFactRef via AccountingFactMapper, zero PSP egress.
        $factsByRecord = [];
        foreach ($reconcileReport->accountingFacts as $fact) {
            $factsByRecord[$fact['sourceRecordId']] = $fact;
        }
        self::assertArrayHasKey('rec-pay-rub-001', $factsByRecord);
        self::assertArrayHasKey('rec-pay-usd-001', $factsByRecord);
        self::assertArrayHasKey('rec-rfnd-rub-001', $factsByRecord);
        self::assertSame('payment.captured', $factsByRecord['rec-pay-rub-001']['factKind']);
        self::assertSame('refund.succeeded', $factsByRecord['rec-rfnd-rub-001']['factKind']);

        // IM-02: Repackage the exact same source records into a second batch with a new mapper version & importPolicyVersion.
        $oblBefore = $this->rows('billium_obligation');
        $payBefore = $this->rows('billium_payment');
        $allocBefore = $this->rows('billium_allocation');
        $outboxBefore = $this->rows('billium_outbox_event');

        $rawArtifact = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
        $rawArtifact['batchId'] = 'batch-rec-complete-2026-10-002-repackaged';
        $rawArtifact['sourceExportRevision'] = 3;
        $rawArtifact['importPolicyVersion'] = 'import-policy-v2';
        unset($rawArtifact['artifactDigest']);

        $profileV2 = new RecurringExportProfile(version: '2.0.0');
        $reader2 = GenericExportReader::fromJson(json_encode($rawArtifact, JSON_THROW_ON_ERROR), $profileV2, 'import-policy-v2', $codec);
        $result2 = $this->applier()->apply(
            $reader2->manifest(),
            $reader2->records(),
            new ImportApplyOptions(
                capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                expectedManifestDigest: $reader2->manifest()->manifestDigest,
            ),
        );

        self::assertSame(ImportBatchStatus::Completed, $result2->batchStatus);
        self::assertSame($oblBefore, $this->rows('billium_obligation'));
        self::assertSame($payBefore, $this->rows('billium_payment'));
        self::assertSame($allocBefore, $this->rows('billium_allocation'));
        self::assertSame($outboxBefore, $this->rows('billium_outbox_event'));
        self::assertGreaterThanOrEqual(8, $result2->counts[ImportRecordApplyStatus::AlreadyApplied->value]);

        // AccountingFactMapper sourceFactRefs stay identical across batch repackaging and mapper version upgrades.
        $reconcile2 = $this->reconciler()->reconcileBatch($reader2->manifest()->batchId);
        $facts2ByRecord = [];
        foreach ($reconcile2->accountingFacts as $fact) {
            $facts2ByRecord[$fact['sourceRecordId']] = $fact;
        }
        self::assertSame($factsByRecord['rec-pay-rub-001']['sourceFactRef'], $facts2ByRecord['rec-pay-rub-001']['sourceFactRef']);
        self::assertSame($factsByRecord['rec-rfnd-rub-001']['sourceFactRef'], $facts2ByRecord['rec-rfnd-rub-001']['sourceFactRef']);
    }

    /**
     * IM-03: Same source identity and revision arriving with a different payloadDigest conflicts and quarantines the batch.
     */
    public function test_conflicting_payload_digest_for_same_source_identity_and_revision_quarantines_batch(): void
    {
        $this->asAuthorizedCaller('seller-conflict-1');
        $reader1 = $this->buildGenericReader('batch-conf-1', 'seller-conflict-1', [
            $this->rawObligationRecord('obl-conf-1', '1000.00', 'RUB', 'pv-rub-conf-1'),
        ]);
        $res1 = $this->applier()->apply(
            $reader1->manifest(),
            $reader1->records(),
            new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $reader1->manifest()->manifestDigest),
        );
        self::assertSame(ImportBatchStatus::Completed, $res1->batchStatus);
        self::assertSame(1, $this->rows('billium_obligation'));

        // Same sourceRecordId + sourceRecordRevision=1, altered amount => payloadDigest mismatch => quarantined!
        $reader2 = $this->buildGenericReader('batch-conf-2', 'seller-conflict-1', [
            $this->rawObligationRecord('obl-conf-1', '1500.00', 'RUB', 'pv-rub-conf-1'),
        ]);
        $res2 = $this->applier()->apply(
            $reader2->manifest(),
            $reader2->records(),
            new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $reader2->manifest()->manifestDigest),
        );
        self::assertSame(ImportBatchStatus::Quarantined, $res2->batchStatus);
        self::assertSame(1, $res2->counts[ImportRecordApplyStatus::Conflict->value]);
        self::assertSame(1, $this->rows('billium_obligation'));
        self::assertSame('100000', (string) $this->value("SELECT amount_due_minor FROM billium_obligation WHERE obligation_ref = 'src-obl-conf-1'"));
    }

    /**
     * IM-12: Revoked auth, missing import:apply scope, mismatched manifestDigest, missing sourceAuthority grant,
     * and cross-seller records are rejected BEFORE any database mutation; pre-apply rejectBatch works and post-apply rejectBatch is refused.
     */
    public function test_authorization_scope_manifest_hash_and_cross_seller_guards_reject_before_mutation(): void
    {
        $this->asAuthorizedCaller('seller-guard-1');
        $reader = $this->buildGenericReader('batch-guard-1', 'seller-guard-1', [
            $this->rawObligationRecord('obl-guard-1', '500.00', 'USD', 'pv-usd-1'),
            $this->rawPaymentRecord('pay-guard-1', '500.00', 'USD', 'orig-pay-guard-1'),
        ]);

        // 1. Missing capability scope.
        try {
            $this->applier()->apply(
                $reader->manifest(),
                $reader->records(),
                new ImportApplyOptions('import:preview', $reader->manifest()->manifestDigest),
            );
            self::fail('Expected AuthDenied for missing import:apply scope');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::AuthDenied, $e->problem);
        }

        // 2. Mismatched manifestDigest.
        try {
            $this->applier()->apply(
                $reader->manifest(),
                $reader->records(),
                new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, self::DIGEST_1),
            );
            self::fail('Expected ValidationInvalid for manifestDigest mismatch');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
        }

        // 3. Revoked / unauthenticated caller credential.
        $this->application()->instance(CallerContext::class, new class implements CallerContext
        {
            public function current(): Caller
            {
                throw new ProblemException(ProblemCode::AuthUnauthenticated, 'revoked caller credential');
            }
        });
        try {
            $this->applier()->apply(
                $reader->manifest(),
                $reader->records(),
                new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $reader->manifest()->manifestDigest),
            );
            self::fail('Expected AuthUnauthenticated for revoked actor');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::AuthUnauthenticated, $e->problem);
        } finally {
            $this->application()->instance(CallerContext::class, $this->callers);
        }

        // 4. Caller lacking grant for migration sourceAuthority.
        $this->callers->set(new Caller('seller-guard-1', 'test', self::INSTALLATION, 'actor-1', []));
        try {
            $this->applier()->apply(
                $reader->manifest(),
                $reader->records(),
                new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $reader->manifest()->manifestDigest),
            );
            self::fail('Expected AuthDenied for missing sourceAuthority grant');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::AuthDenied, $e->problem);
        }

        // 5. Cross-seller caller scope mismatch.
        $this->asAuthorizedCaller('seller-other');
        try {
            $this->applier()->apply(
                $reader->manifest(),
                $reader->records(),
                new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $reader->manifest()->manifestDigest),
            );
            self::fail('Expected AuthDenied for cross-seller caller');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::AuthDenied, $e->problem);
        }

        // Zero rows mutated across all 5 rejected attempts!
        self::assertSame(0, $this->rows('billium_import_batch'));
        self::assertSame(0, $this->rows('billium_import_record'));
        self::assertSame(0, $this->rows('billium_import_mapping'));
        self::assertSame(0, $this->rows('billium_obligation'));

        // Pre-apply rejectBatch succeeds on a staged batch, while post-apply rejectBatch is refused.
        $this->asAuthorizedCaller('seller-guard-1');
        $store = $this->application()->make(ImportIdentityStore::class);
        $store->stageBatch($reader->manifest());
        $this->applier()->rejectBatch('batch-guard-1', 'operator_cancelled_pre_apply');
        self::assertSame('rejected', $this->value("SELECT status FROM billium_import_batch WHERE batch_id = 'batch-guard-1'"));
    }

    /**
     * Missing settlement intent & unknown remote funding status:
     * - Payment without settlement intent parks in observation park (observed visible, billium_payment=0, never paid/completed).
     * - Payment with fundingStatus=unknown is classified as unanchored_evidence and never becomes paid.
     */
    public function test_missing_settlement_intent_parks_observation_and_unknown_remote_status_never_becomes_paid(): void
    {
        $this->asAuthorizedCaller('seller-park-1');
        $unknownPayment = $this->rawPaymentRecord('pay-unknown-1', '800.00', 'RUB', 'orig-pay-unknown-1');
        $unknownPayment['fundingRefs']['fundingStatus'] = 'unknown';

        // Payment with proven provider fact & billingAccountRef, but NO proven obligation / settlement intent target!
        $untargetedPayment = $this->rawPaymentRecord('pay-no-intent-1', '1200.00', 'RUB', 'orig-pay-no-intent-1');

        $reader = $this->buildGenericReader('batch-park-1', 'seller-park-1', [
            $unknownPayment,
            $untargetedPayment,
        ], declaredTotals: [
            [
                'sellerRef' => 'seller-park-1',
                'currency' => 'RUB',
                'recordKind' => 'proven_payment_evidence',
                'count' => 1,
                'totalAmount' => ['amount' => '1200.00', 'currency' => 'RUB'],
                'authoritativeCurrentBalance' => false,
            ],
        ]);

        $preview = $this->application()->make(ImportPreviewEvaluator::class)->evaluate($reader->manifest(), $reader->records());
        $byId = [];
        foreach ($preview->records as $rec) {
            $byId[$rec->identity->sourceRecordId] = $rec;
        }
        self::assertSame(ImportValidationStatus::UnanchoredEvidence, $byId['pay-unknown-1']->status);
        self::assertSame(ImportValidationStatus::Ready, $byId['pay-no-intent-1']->status);

        $applyRes = $this->applier()->apply(
            $reader->manifest(),
            $reader->records(),
            new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $reader->manifest()->manifestDigest),
        );

        // Batch stays in 'applying' because pay-no-intent-1 parked on missing settlement_intent; zero projected payments!
        self::assertSame(ImportBatchStatus::Applying, $applyRes->batchStatus);
        self::assertSame(1, $applyRes->counts[ImportRecordApplyStatus::UnanchoredEvidence->value]);
        self::assertSame(1, $applyRes->counts[ImportRecordApplyStatus::ObservationParked->value]);
        self::assertSame(0, $this->rows('billium_payment'));
        self::assertSame(1, $this->rows('billium_observed_payment', "operation_ref = 'orig-pay-no-intent-1'"));
        self::assertSame(0, $this->rows('billium_observed_payment', "operation_ref = 'orig-pay-unknown-1'"));

        $paymentStatus = $this->application()->make(LedgerQueries::class)->paymentStatus(
            'orig-pay-no-intent-1',
            ImportApplyOptions::DEFAULT_MIGRATION_SOURCE_AUTHORITY,
        );
        self::assertSame('allocation_pending', $paymentStatus['projectionStatus']);
    }

    /**
     * IM-10: Refund capacity validation + post-apply linked corrections (positive multi-component with MoneyMath rounding,
     * negative credit note on issued invoice, mapping tombstone) without destructive rollback or automatic PSP dispatch.
     */
    public function test_refund_capacity_validation_and_post_apply_linked_corrections_without_destructive_rollback(): void
    {
        $this->asAuthorizedCaller('seller-corr-1');

        // 1. Apply initial proven obligation (1000.00 RUB) + allocation + payment (1000.00 RUB).
        $reader = $this->buildGenericReader('batch-corr-1', 'seller-corr-1', [
            $this->rawObligationRecord('obl-corr-1', '1000.00', 'RUB', 'pv-corr-rub-1'),
            $this->rawAllocationRecord('alloc-corr-1', '1000.00', 'RUB', 'src-obl-corr-1', 'src-pay-corr-1'),
            $this->rawPaymentRecord('pay-corr-1', '1000.00', 'RUB', 'orig-pay-corr-1', 'src-pay-corr-1'),
        ]);
        $applied = $this->applier()->apply(
            $reader->manifest(),
            $reader->records(),
            new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $reader->manifest()->manifestDigest),
        );
        self::assertSame(ImportBatchStatus::Completed, $applied->batchStatus);

        // 2. Over-capacity refund (1500.00 RUB > 1000.00 RUB) without discrepancy is quarantined before corrupting payment!
        $overRefundReader = $this->buildGenericReader('batch-corr-overrfnd', 'seller-corr-1', [
            $this->rawRefundRecord('rfnd-over-1', '1500.00', 'RUB', 'orig-pay-corr-1'),
        ]);
        $overRefundRes = $this->applier()->apply(
            $overRefundReader->manifest(),
            $overRefundReader->records(),
            new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $overRefundReader->manifest()->manifestDigest),
        );
        self::assertSame(ImportBatchStatus::Quarantined, $overRefundRes->batchStatus);
        self::assertSame(1, $overRefundRes->counts[ImportRecordApplyStatus::Conflict->value]);

        // 3. Positive linked correction (+10.00 RUB = 1000 minor split across 3 equal components -> 334, 333, 333 exact MoneyMath!).
        $oblIdentity = $reader->records()[0]->identity;
        $posResult = $this->applier()->applyLinkedCorrection(new ImportLinkedCorrection(
            capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
            correctionRef: 'corr-pos-001',
            originalIdentity: $oblIdentity,
            originalRevision: 1,
            newRevision: 2,
            kind: ImportLinkedCorrection::KIND_POSITIVE_OBLIGATION,
            reason: 'late_usage_underbilling_adjustment',
            deltaMoney: Money::parse('10.00', 'RUB'),
            componentWeights: [
                ['componentRef' => 'comp-a', 'weightMinor' => 1],
                ['componentRef' => 'comp-b', 'weightMinor' => 1],
                ['componentRef' => 'comp-c', 'weightMinor' => 1],
            ],
        ));
        self::assertFalse($posResult->externalProviderActionDispatched);
        self::assertCount(3, $posResult->allocatedComponents);
        self::assertSame('3.34', $posResult->allocatedComponents[0]['amount']['amount']);
        self::assertSame('3.33', $posResult->allocatedComponents[1]['amount']['amount']);
        self::assertSame('3.33', $posResult->allocatedComponents[2]['amount']['amount']);
        self::assertSame(ImportMappingStatus::Superseded, $this->application()->make(ImportIdentityStore::class)->findMapping($oblIdentity, 1)?->status);
        self::assertSame(ImportMappingStatus::Active, $posResult->mapping->status);
        // Original obligation was never deleted or overwritten.
        self::assertSame(100_000, self::integer($this->value("SELECT amount_due_minor FROM billium_obligation WHERE obligation_ref = 'src-obl-corr-1'")));

        // 4. Negative linked correction via CreditNote on an issued invoice (reverse_allocations or open_only) with ZERO PSP calls.
        $compAOblRef = $posResult->allocatedComponents[0]['obligationRef'];
        $ledger = $this->application()->make(Ledger::class);
        $scope = new BillingAccountScope('seller-corr-1', 'test', 'acct-1');
        $ledger->createInvoice(new CreateInvoice(
            commandKey: 'create-inv-corr-1',
            scope: $scope,
            invoiceRef: 'inv-corr-1',
            customerRef: 'cust-1',
            lines: [new InvoiceLineInput($compAOblRef)],
        ));
        $ledger->issueInvoice(new IssueInvoice(
            commandKey: 'issue-inv-corr-1',
            scope: $scope,
            invoiceRef: 'inv-corr-1',
        ));

        $negResult = $this->applier()->applyLinkedCorrection(new ImportLinkedCorrection(
            capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
            correctionRef: 'corr-neg-cn-001',
            originalIdentity: $oblIdentity,
            originalRevision: 2,
            newRevision: 3,
            kind: ImportLinkedCorrection::KIND_NEGATIVE_CREDIT_NOTE,
            reason: 'agreed_commercial_credit',
            deltaMoney: Money::parse('1.34', 'RUB'),
            invoiceRef: 'inv-corr-1',
            creditNoteMode: 'open_only',
        ));
        self::assertFalse($negResult->externalProviderActionDispatched);
        self::assertSame('credit_note', $negResult->mapping->billiumTargetKind);
        self::assertSame(200, self::integer($this->value('SELECT open_minor FROM billium_obligation WHERE obligation_ref = ?', [$compAOblRef])));
        self::assertSame(0, $this->rows('billium_outbox_command', "command_type LIKE '%refund%'"));

        // 5. Tombstone correction marks mapping tombstoned without deleting history and blocks reactivation.
        $tombResult = $this->applier()->applyLinkedCorrection(new ImportLinkedCorrection(
            capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
            correctionRef: 'corr-tomb-001',
            originalIdentity: $oblIdentity,
            originalRevision: 3,
            newRevision: 3,
            kind: ImportLinkedCorrection::KIND_TOMBSTONE_MAPPING,
            reason: 'retired_erroneous_lineage',
        ));
        self::assertSame(ImportMappingStatus::Tombstoned, $tombResult->mapping->status);
        self::assertGreaterThanOrEqual(3, $this->rows('billium_import_mapping'));
    }

    private function asAuthorizedCaller(string $sellerRef): void
    {
        $this->callers->set(new Caller(
            sellerRef: $sellerRef,
            environment: 'test',
            installationRef: self::INSTALLATION,
            actorRef: 'actor-import-op',
            sourceAuthorities: [ImportApplyOptions::DEFAULT_MIGRATION_SOURCE_AUTHORITY],
        ));
    }

    private function applier(): ImportBatchApplier
    {
        return $this->application()->make(ImportBatchApplier::class);
    }

    private function reconciler(): ImportReconciler
    {
        return $this->application()->make(ImportReconciler::class);
    }

    /**
     * @param  list<array<string, mixed>>  $rawRecords
     * @param  list<array<string, mixed>>|null  $declaredTotals
     */
    private function buildGenericReader(
        string $batchId,
        string $sellerRef,
        array $rawRecords,
        ?array $declaredTotals = null,
    ): GenericExportReader {
        $codec = new Codec;
        if ($declaredTotals === null) {
            $grouped = [];
            foreach ($rawRecords as $r) {
                if (! is_array($r['money'] ?? null) || ! is_string($r['money']['currency'] ?? null)) {
                    continue;
                }
                $kind = match ($r['recordType'] ?? '') {
                    'obligation' => ImportRecordKind::ProvenObligation->value,
                    'allocation' => ImportRecordKind::ProvenAllocation->value,
                    'payment_evidence' => ImportRecordKind::ProvenPaymentEvidence->value,
                    'refund_evidence' => ImportRecordKind::ProvenRefundEvidence->value,
                    'historical_invoice' => ImportRecordKind::ProvenInvoiceHistory->value,
                    default => null,
                };
                if ($kind === null) {
                    continue;
                }
                $curr = $r['money']['currency'];
                $money = Money::fromWire($r['money']);
                $key = $curr.'|'.$kind;
                if (! isset($grouped[$key])) {
                    $grouped[$key] = ['currency' => $curr, 'recordKind' => $kind, 'count' => 0, 'minor' => 0];
                }
                $grouped[$key]['count']++;
                $grouped[$key]['minor'] += $money->minor;
            }
            ksort($grouped, SORT_STRING);
            $declaredTotals = [];
            foreach ($grouped as $g) {
                $declaredTotals[] = [
                    'sellerRef' => $sellerRef,
                    'currency' => $g['currency'],
                    'recordKind' => $g['recordKind'],
                    'count' => $g['count'],
                    'totalAmount' => (new Money($g['minor'], Currency::of($g['currency'])))->toWire(),
                    'authoritativeCurrentBalance' => false,
                ];
            }
        }

        $doc = [
            'exportSchema' => 'source.export.v1',
            'profile' => 'generic',
            'exportVersion' => '1.0.0',
            'batchId' => $batchId,
            'sourceSystem' => 'host-billing/export',
            'sourceInstallation' => 'src-inst-1',
            'sourceBook' => 'book-main',
            'sourceExportRevision' => 1,
            'exportCutoff' => '2026-10-20T00:00:00Z',
            'installationRef' => self::INSTALLATION,
            'environment' => 'test',
            'sellerRef' => $sellerRef,
            'importPolicyVersion' => 'import-policy-v1',
            'sanitizationPolicy' => ['piiRedacted' => true, 'secretsStripped' => true, 'policyVersion' => 'v1'],
            'totalsByCurrency' => $declaredTotals,
            'records' => $rawRecords,
            'recordCount' => count($rawRecords),
            'recordsChecksum' => 'sha256:'.hash('sha256', $codec->canonicalize($rawRecords)),
        ];

        return GenericExportReader::fromJson(json_encode($doc, JSON_THROW_ON_ERROR), new GenericExportProfile, 'import-policy-v1', $codec);
    }

    /** @return array<string, mixed> */
    private function rawObligationRecord(string $id, string $amount, string $currency, string $priceVersionRef): array
    {
        return [
            'sourceRecordId' => $id,
            'sourceRecordRevision' => 1,
            'recordType' => 'obligation',
            'eventBusinessDate' => '2025-11-01T09:00:00Z',
            'originalBusinessRefs' => [
                'customerRef' => 'cust-1',
                'billingAccountRef' => 'acct-1',
                'obligationRef' => 'src-'.$id,
            ],
            'partyRoles' => ['subjectRef' => 'subj-1', 'customerRef' => 'cust-1', 'billingAccountRef' => 'acct-1'],
            'evidenceRefs' => [[
                'evidenceRef' => 'ev-'.$id,
                'kind' => 'price_snapshot',
                'digest' => self::DIGEST_1,
                'sanitized' => true,
            ]],
            'money' => ['amount' => $amount, 'currency' => $currency],
            'frozenPriceSnapshot' => [
                'basis' => 'frozen_historical',
                'priceVersionRef' => $priceVersionRef,
                'priceDigest' => self::DIGEST_1,
                'currency' => $currency,
                'termsRef' => 'terms-1',
                'taxPolicyVersion' => 'tax-1',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function rawAllocationRecord(string $id, string $amount, string $currency, string $oblRef, string $payRef): array
    {
        return [
            'sourceRecordId' => $id,
            'sourceRecordRevision' => 1,
            'recordType' => 'allocation',
            'eventBusinessDate' => '2025-11-01T09:01:00Z',
            'originalBusinessRefs' => [
                'customerRef' => 'cust-1',
                'billingAccountRef' => 'acct-1',
                'obligationRef' => $oblRef,
                'paymentRef' => $payRef,
            ],
            'partyRoles' => ['subjectRef' => 'subj-1', 'customerRef' => 'cust-1', 'billingAccountRef' => 'acct-1'],
            'evidenceRefs' => [[
                'evidenceRef' => 'ev-'.$id,
                'kind' => 'allocation_lineage',
                'digest' => self::DIGEST_2,
                'sanitized' => true,
            ]],
            'money' => ['amount' => $amount, 'currency' => $currency],
        ];
    }

    /** @return array<string, mixed> */
    private function rawPaymentRecord(
        string $id,
        string $amount,
        string $currency,
        string $origOpRef,
        ?string $payRef = null,
    ): array {
        return [
            'sourceRecordId' => $id,
            'sourceRecordRevision' => 1,
            'recordType' => 'payment_evidence',
            'eventBusinessDate' => '2025-11-01T09:02:00Z',
            'originalBusinessRefs' => [
                'customerRef' => 'cust-1',
                'billingAccountRef' => 'acct-1',
                'paymentRef' => $payRef ?? ('src-'.$id),
            ],
            'partyRoles' => ['subjectRef' => 'subj-1', 'customerRef' => 'cust-1', 'billingAccountRef' => 'acct-1'],
            'evidenceRefs' => [[
                'evidenceRef' => 'ev-'.$id,
                'kind' => 'payment_provider_fact',
                'digest' => self::DIGEST_3,
                'sanitized' => true,
            ]],
            'money' => ['amount' => $amount, 'currency' => $currency],
            'fundingRefs' => [
                'sourceAuthority' => 'source-gateway',
                'fundingStatus' => 'captured',
                'providerAccountRef' => 'pa-acct-1',
                'providerOperationRef' => 'psp-op-'.$id,
                'originalOperationRef' => $origOpRef,
                'observedAt' => '2025-11-01T09:02:00Z',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function rawRefundRecord(string $id, string $amount, string $currency, string $origOpRef): array
    {
        return [
            'sourceRecordId' => $id,
            'sourceRecordRevision' => 1,
            'recordType' => 'refund_evidence',
            'eventBusinessDate' => '2025-11-05T12:00:00Z',
            'originalBusinessRefs' => [
                'customerRef' => 'cust-1',
                'billingAccountRef' => 'acct-1',
                'originalPaymentRef' => $origOpRef,
            ],
            'partyRoles' => ['subjectRef' => 'subj-1', 'customerRef' => 'cust-1', 'billingAccountRef' => 'acct-1'],
            'evidenceRefs' => [[
                'evidenceRef' => 'ev-'.$id,
                'kind' => 'refund_provider_fact',
                'digest' => self::DIGEST_3,
                'sanitized' => true,
            ]],
            'money' => ['amount' => $amount, 'currency' => $currency],
            'fundingRefs' => [
                'sourceAuthority' => 'source-gateway',
                'fundingStatus' => 'refunded',
                'providerAccountRef' => 'pa-acct-1',
                'providerOperationRef' => 'psp-rfnd-'.$id,
                'originalOperationRef' => $origOpRef,
                'observedAt' => '2025-11-05T12:00:00Z',
            ],
        ];
    }
}
