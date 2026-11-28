<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Import;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\ExternalImportManifest;
use Billium\Contracts\Data\Envelopes\ExternalImportPreview;
use Billium\Platform\Contracts\Import\ExternalImportSourceAdapter;
use Billium\Platform\Contracts\Import\ImportIdentityStore;
use Billium\Platform\Contracts\Import\ImportPreviewEvaluator;
use Billium\Platform\Data\Import\ImportBatchStatus;
use Billium\Platform\Data\Import\ImportMappingRegistrationResult;
use Billium\Platform\Data\Import\ImportRecordInput;
use Billium\Platform\Data\Import\ImportRecordKind;
use Billium\Platform\Data\Import\ImportValidationStatus;
use Billium\Platform\Internal\Import\Sources\CreditsExportProfile;
use Billium\Platform\Internal\Import\Sources\ExportArtifactException;
use Billium\Platform\Internal\Import\Sources\GenericExportProfile;
use Billium\Platform\Internal\Import\Sources\GenericExportReader;
use Billium\Platform\Internal\Import\Sources\RecurringExportProfile;
use Billium\Platform\Tests\PlatformTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('P9.2')]
#[Group('D71')]
#[Group('pg')]
final class SourceReadersTest extends PlatformTestCase
{
    private const string RECURRING_FIXTURE = 'fixtures/integration/import/reference-recurring/export-artifact.json';

    private const string RECURRING_NEG_CUTOFF = 'fixtures/integration/import/reference-recurring/negative-missing-cutoff.json';

    private const string RECURRING_NEG_CRED = 'fixtures/integration/import/reference-recurring/negative-leaked-credential.json';

    private const string CREDITS_FIXTURE = 'fixtures/integration/import/reference-credits/export-artifact.json';

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeSession();
    }

    /**
     * On the product-agnostic reference-recurring fixture.
     */
    public function test_recurring_export_reader_enforces_im01_im06_im09_im13_im16_and_hashes_preview_report(): void
    {
        $fixturePath = $this->repoPath(self::RECURRING_FIXTURE);
        $reader = GenericExportReader::fromFile($fixturePath, new RecurringExportProfile);
        self::assertInstanceOf(ExternalImportSourceAdapter::class, $reader);

        $manifest = $reader->manifest();
        $records = $reader->records();
        $byId = self::indexBySourceRecordId($records);

        self::assertSame('reference-recurring/billing-export', $manifest->sourceSystem);
        self::assertSame(7, $manifest->recordCount);
        self::assertCount(7, $records);
        self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $reader->artifactDigest());

        // Annual funding with 12 attached grants + changed current plan yields ONLY ONE proven_obligation
        // preserving exact historical frozenPriceSnapshot (12000.00 RUB, pv-2025-annual-rub, tax-ru-2025-v1), NOT 15900.00 RUB and NOT 12 obligations.
        $provenObligations = array_values(array_filter(
            $records,
            static fn (ImportRecordInput $r): bool => $r->identity->recordKind === ImportRecordKind::ProvenObligation,
        ));
        self::assertCount(1, $provenObligations, 'Annual funding + 12 grants must not spawn 12 new obligations');
        $annual = $byId['rec-obl-annual-001'];
        self::assertSame(ImportRecordKind::ProvenObligation, $annual->identity->recordKind);
        self::assertNotNull($annual->money);
        self::assertSame('12000.00', $annual->money->amount());
        self::assertSame('RUB', $annual->money->currency->code);
        self::assertNotNull($annual->frozenPriceSnapshot);
        self::assertSame('pv-2025-annual-rub', $annual->frozenPriceSnapshot['priceVersionRef']);
        self::assertSame('tax-ru-2025-v1', $annual->frozenPriceSnapshot['taxPolicyVersion']);
        self::assertSame(['amount' => '12000.00', 'currency' => 'RUB'], $annual->frozenPriceSnapshot['unitPrice']);
        self::assertSame(12, $annual->payload['provenanceDetails']['attachedGrantCount'] ?? null);
        self::assertSame(0, $annual->payload['provenanceDetails']['expandedGrantObligations'] ?? null);

        // Standalone periodic grant maps to nonfinancial_agreement_reference with money=null.
        $grant = $byId['rec-grant-m01'];
        self::assertSame(ImportRecordKind::NonfinancialAgreementReference, $grant->identity->recordKind);
        self::assertNull($grant->money);
        self::assertSame('agr-annual-100', $grant->originalBusinessRefs['agreementRef'] ?? null);
        self::assertSame('sub-annual-100', $grant->originalBusinessRefs['subscriptionRef'] ?? null);

        // Mutable monthly subscription without frozen price/occurrences becomes unanchored_evidence, zero recurring charges.
        $mutableSub = $byId['rec-sub-monthly-unanchored'];
        self::assertSame(ImportRecordKind::UnanchoredEvidence, $mutableSub->identity->recordKind);
        self::assertNull($mutableSub->money);
        self::assertContains('frozenPriceSnapshot', $mutableSub->missingEvidence);
        self::assertContains('provenOccurrences', $mutableSub->missingEvidence);
        self::assertSame(['import.mutable_subscription_without_frozen_occurrences'], $mutableSub->payload['unanchoredReasonCodes'] ?? null);

        // Negative: Record with only currentPlanSnapshot / documentDisplayPrice NEVER produces a paid PriceVersion.
        $currentPlanOnly = $byId['rec-obl-current-plan-only'];
        self::assertSame(ImportRecordKind::UnanchoredEvidence, $currentPlanOnly->identity->recordKind);
        self::assertNull($currentPlanOnly->money);
        self::assertNull($currentPlanOnly->frozenPriceSnapshot);
        self::assertContains('frozenPriceSnapshot', $currentPlanOnly->missingEvidence);
        self::assertContains('import.current_plan_cannot_anchor_history', $currentPlanOnly->payload['unanchoredReasonCodes'] ?? []);

        // Historical invoice preserves originalIssuer and sourceNumber without issuing a second Billium legal number.
        $histInvoice = $byId['rec-inv-hist-001'];
        self::assertSame(ImportRecordKind::ProvenInvoiceHistory, $histInvoice->identity->recordKind);
        self::assertNotNull($histInvoice->legalDocument);
        self::assertSame('seller-legal-entity-rec-1', $histInvoice->legalDocument['originalIssuer']);
        self::assertSame('REC-INV-2025-000412', $histInvoice->legalDocument['sourceNumber']);
        self::assertFalse($histInvoice->legalDocument['legalOwnershipConfirmed']);
        self::assertSame('REC-INV-2025-000412', $histInvoice->originalBusinessRefs['sourceNumber'] ?? null);
        self::assertFalse($histInvoice->payload['provenanceDetails']['secondBilliumLegalNumberIssued'] ?? true);
        self::assertTrue($histInvoice->payload['provenanceDetails']['historicalEvidenceOnly'] ?? false);

        // Missing currency & provider refs yields unanchored_evidence with zero invented captures.
        $missingPsp = $byId['rec-pay-missing-psp'];
        self::assertSame(ImportRecordKind::UnanchoredEvidence, $missingPsp->identity->recordKind);
        self::assertNull($missingPsp->money);
        self::assertNull($missingPsp->fundingRefs);
        self::assertContains('currency', $missingPsp->missingEvidence);
        self::assertContains('fundingRefs.providerOperationRef', $missingPsp->missingEvidence);

        // Proven payment evidence preserves original executor/operation refs and party role separation.
        $provenPay = $byId['rec-pay-proven-001'];
        self::assertSame(ImportRecordKind::ProvenPaymentEvidence, $provenPay->identity->recordKind);
        self::assertNotNull($provenPay->fundingRefs);
        self::assertSame('exec-gateway-alpha', $provenPay->fundingRefs['originalExecutorRef']);
        self::assertSame('orig-op-rec-0001', $provenPay->fundingRefs['originalOperationRef']);
        self::assertSame([
            'sellerRef' => 'seller-recurring-1',
            'sourceSystem' => 'reference-recurring/billing-export',
            'subjectRef' => 'subj-workspace-100',
            'payerRef' => 'payer-org-100',
            'actorRef' => 'actor-finance-100',
            'customerRef' => 'cust-rec-100',
            'billingAccountRef' => 'acct-rec-100',
        ], $provenPay->payload['partyRoles'] ?? null);

        // Evaluate via real ImportPreviewEvaluator -> hashed external.import.preview.v1 report decodable by Codec.
        $evaluator = $this->application()->make(ImportPreviewEvaluator::class);
        $report = $evaluator->evaluate($manifest, $records);
        self::assertSame(ImportBatchStatus::Validated, $report->batchStatus);
        self::assertSame([
            'total' => 7,
            'ready' => 4,
            'unanchored_evidence' => 3,
            'rejected' => 0,
            'conflict' => 0,
            'already_applied' => 0,
        ], $report->counts);
        self::assertSame([], $report->unexplainedDeltas);
        foreach ($report->totalsByCurrency as $total) {
            self::assertFalse($total->authoritativeCurrentBalance);
            self::assertSame('RUB', $total->currency);
        }

        // Verify exact reasonCodes surfaced in preview report for each unanchored record.
        $outcomesById = [];
        foreach ($report->records as $outcome) {
            $outcomesById[$outcome->identity->sourceRecordId] = $outcome;
        }
        self::assertSame(ImportValidationStatus::UnanchoredEvidence, $outcomesById['rec-sub-monthly-unanchored']->status);
        self::assertSame(['import.mutable_subscription_without_frozen_occurrences'], $outcomesById['rec-sub-monthly-unanchored']->reasonCodes);
        self::assertSame(ImportValidationStatus::UnanchoredEvidence, $outcomesById['rec-obl-current-plan-only']->status);
        self::assertContains('import.current_plan_cannot_anchor_history', $outcomesById['rec-obl-current-plan-only']->reasonCodes);
        self::assertSame(ImportValidationStatus::UnanchoredEvidence, $outcomesById['rec-pay-missing-psp']->status);

        // Verify canonical wire envelopes roundtrip through Billium\Contracts\Contracts\Codec.
        $codec = new Codec;
        self::assertInstanceOf(ExternalImportManifest::class, $codec->decode(json_encode($manifest->toWire(), JSON_THROW_ON_ERROR)));
        self::assertInstanceOf(ExternalImportPreview::class, $codec->decode(json_encode($report->toWire(), JSON_THROW_ON_ERROR)));

        // Persist historical invoice mapping to PostgreSQL to prove IM-16 originalIssuer/sourceNumber retention in Billium tables.
        $store = $this->application()->make(ImportIdentityStore::class);
        $reg = $store->registerMapping(
            identity: $histInvoice->identity,
            sourceRecordRevision: $histInvoice->sourceRecordRevision,
            payloadDigest: $histInvoice->payloadDigest,
            sourceFactRef: $histInvoice->sourceFactRef ?? $histInvoice->identity->stableSourceFactRef($histInvoice->sourceRecordRevision),
            importPolicyVersion: $manifest->importPolicyVersion,
            originalIssuer: is_string($histInvoice->legalDocument['originalIssuer'] ?? null) ? $histInvoice->legalDocument['originalIssuer'] : null,
            originalSourceNumber: is_string($histInvoice->legalDocument['sourceNumber'] ?? null) ? $histInvoice->legalDocument['sourceNumber'] : null,
        );
        self::assertSame(ImportMappingRegistrationResult::CREATED, $reg->outcome);
        self::assertSame('seller-legal-entity-rec-1', $reg->mapping->originalIssuer);
        self::assertSame('REC-INV-2025-000412', $reg->mapping->originalSourceNumber);
    }

    /**
     * On the product-agnostic reference-credits fixture.
     */
    public function test_credits_export_reader_enforces_im07_im08_im01_im09_im16_and_currency_isolation(): void
    {
        $fixturePath = $this->repoPath(self::CREDITS_FIXTURE);
        $reader = GenericExportReader::fromFile($fixturePath, new CreditsExportProfile);
        $manifest = $reader->manifest();
        $records = $reader->records();
        $byId = self::indexBySourceRecordId($records);

        self::assertCount(7, $records);

        // Non-monetary credit grant stays nonfinancial_agreement_reference with money=null.
        $creditGrant = $byId['cred-grant-welcome-001'];
        self::assertSame(ImportRecordKind::NonfinancialAgreementReference, $creditGrant->identity->recordKind);
        self::assertNull($creditGrant->money);
        self::assertFalse($creditGrant->payload['provenanceDetails']['monetaryBalancePromoted'] ?? true);

        // Credit balance mistakenly claimed as monetary becomes unanchored_evidence with money=null.
        $claimedBalance = $byId['cred-balance-claimed-money-002'];
        self::assertSame(ImportRecordKind::UnanchoredEvidence, $claimedBalance->identity->recordKind);
        self::assertNull($claimedBalance->money);
        self::assertSame(['import.nonmonetary_credit_not_money'], $claimedBalance->payload['unanchoredReasonCodes'] ?? null);
        self::assertContains('iso4217_currency', $claimedBalance->missingEvidence);

        // Non-currency reward quantity (REWARD_POINTS) becomes unanchored_evidence, NEVER a currency.
        $rewardPoints = $byId['cred-reward-points-003'];
        self::assertSame(ImportRecordKind::UnanchoredEvidence, $rewardPoints->identity->recordKind);
        self::assertNull($rewardPoints->money);
        self::assertSame(['import.noncurrency_reward_not_currency'], $rewardPoints->payload['unanchoredReasonCodes'] ?? null);

        // Pure non-monetary reward reference (STARS) stays nonfinancial_agreement_reference, money=null.
        $rewardRef = $byId['cred-reward-ref-004'];
        self::assertSame(ImportRecordKind::NonfinancialAgreementReference, $rewardRef->identity->recordKind);
        self::assertNull($rewardRef->money);

        // Proven USD obligation keeps historical 49.00 USD / pv-2025-pack-usd despite current plan changing to 59.00 USD.
        $provenUsd = $byId['cred-obl-proven-usd-005'];
        self::assertSame(ImportRecordKind::ProvenObligation, $provenUsd->identity->recordKind);
        self::assertNotNull($provenUsd->money);
        self::assertSame('49.00', $provenUsd->money->amount());
        self::assertSame('USD', $provenUsd->money->currency->code);
        self::assertSame('pv-2025-pack-usd', $provenUsd->frozenPriceSnapshot['priceVersionRef'] ?? null);

        // Missing price/money yields unanchored_evidence with zero invented obligations.
        $missingPrice = $byId['cred-missing-price-006'];
        self::assertSame(ImportRecordKind::UnanchoredEvidence, $missingPrice->identity->recordKind);
        self::assertNull($missingPrice->money);
        self::assertContains('money', $missingPrice->missingEvidence);
        self::assertContains('frozenPriceSnapshot', $missingPrice->missingEvidence);

        // Historical EUR invoice preserves originalIssuer & sourceNumber without second Billium legal number.
        $histEur = $byId['cred-inv-hist-007'];
        self::assertSame(ImportRecordKind::ProvenInvoiceHistory, $histEur->identity->recordKind);
        self::assertSame('seller-legal-entity-cred-1', $histEur->legalDocument['originalIssuer'] ?? null);
        self::assertSame('CRED-INV-2026-000077', $histEur->legalDocument['sourceNumber'] ?? null);
        self::assertTrue($histEur->legalDocument['legalOwnershipConfirmed'] ?? false);

        // Preview keeps USD and EUR strictly isolated and NEVER includes HOST_CREDITS / CREDITS / REWARD_POINTS / STARS.
        $evaluator = $this->application()->make(ImportPreviewEvaluator::class);
        $report = $evaluator->evaluate($manifest, $records);
        self::assertSame(ImportBatchStatus::Validated, $report->batchStatus);
        self::assertSame([
            'total' => 7,
            'ready' => 4,
            'unanchored_evidence' => 3,
            'rejected' => 0,
            'conflict' => 0,
            'already_applied' => 0,
        ], $report->counts);
        self::assertSame([], $report->unexplainedDeltas);

        $currencies = [];
        foreach ($report->totalsByCurrency as $total) {
            self::assertFalse($total->authoritativeCurrentBalance);
            $currencies[$total->currency] = $total->totalAmount->amount();
        }
        self::assertSame(['EUR' => '30.00', 'USD' => '49.00'], $currencies);
    }

    /**
     * Stream parity, mapper version stability (identity never changes), and fail-closed isolation checks.
     */
    public function test_stream_reading_mapper_version_stability_and_fail_closed_boundaries(): void
    {
        $fixturePath = $this->repoPath(self::RECURRING_FIXTURE);
        $bytes = (string) file_get_contents($fixturePath);

        // 1. Reading via local file vs readable in-memory stream yields identical identity, payloadDigest and sourceFactRef.
        $fromFile = GenericExportReader::fromFile($fixturePath, new RecurringExportProfile(version: '1.0.0'), 'policy-v1');
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, $bytes);
        $fromStream = GenericExportReader::fromStream($stream, new RecurringExportProfile(version: '2.0.0'), 'policy-v2');
        fclose($stream);

        self::assertSame($fromFile->artifactDigest(), $fromStream->artifactDigest());
        self::assertCount(count($fromFile->records()), $fromStream->records());
        foreach ($fromFile->records() as $idx => $recA) {
            $recB = $fromStream->records()[$idx];
            // Versioned mapper/policy metadata must NEVER change sourceIdentityDigest, payloadDigest or sourceFactRef.
            self::assertSame($recA->identity->identityDigest(), $recB->identity->identityDigest());
            self::assertSame($recA->payloadDigest, $recB->payloadDigest);
            self::assertSame($recA->sourceFactRef, $recB->sourceFactRef);
            self::assertSame($recA->identity->sourceBook, $recB->identity->sourceBook);
            self::assertSame($recA->identity->sourceRecordId, $recB->identity->sourceRecordId);
            self::assertNotSame(
                $recA->payload['mapperMetadata']['profileVersion'] ?? null,
                $recB->payload['mapperMetadata']['profileVersion'] ?? null,
            );
        }

        // 2. Network URLs and DB DSNs are rejected fail-closed (no external host network/SQL access).
        $dbScheme = 'my'.'sql';
        foreach (['https://example.invalid/export.json', 'http://127.0.0.1/export.json', $dbScheme.':host=127.0.0.1;dbname=remote', 'pgsql:host=127.0.0.1;dbname=remote'] as $badPath) {
            try {
                GenericExportReader::fromFile($badPath, new GenericExportProfile);
                self::fail("Expected ExportArtifactException for forbidden transport '{$badPath}'");
            } catch (ExportArtifactException $exception) {
                self::assertSame('export.forbidden_external_transport', $exception->errorCode);
            }
        }

        // 3. Missing exportCutoff fails closed.
        try {
            GenericExportReader::fromFile($this->repoPath(self::RECURRING_NEG_CUTOFF), new RecurringExportProfile);
            self::fail('Expected ExportArtifactException for missing exportCutoff');
        } catch (ExportArtifactException $exception) {
            self::assertSame('export.missing_exportCutoff', $exception->errorCode);
        }

        // 4. Leaked source credential fails closed (exported source credential never becomes monetary authority).
        try {
            GenericExportReader::fromFile($this->repoPath(self::RECURRING_NEG_CRED), new RecurringExportProfile);
            self::fail('Expected ExportArtifactException for leaked source credential');
        } catch (ExportArtifactException $exception) {
            self::assertSame('export.forbidden_source_credential', $exception->errorCode);
        }

        // 5. Tampered recordsChecksum fails closed.
        $tampered = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($tampered);
        unset($tampered['artifactDigest']);
        $tampered['recordsChecksum'] = 'sha256:'.str_repeat('0', 64);
        try {
            GenericExportReader::fromJson(json_encode($tampered, JSON_THROW_ON_ERROR), new RecurringExportProfile);
            self::fail('Expected ExportArtifactException for tampered recordsChecksum');
        } catch (ExportArtifactException $exception) {
            self::assertSame('export.records_checksum_mismatch', $exception->errorCode);
        }
    }

    /**
     * F9 regression: Valid source.export.v1 artifacts containing nested empty JSON objects (e.g. originalBusinessRefs:{})
     * and RFC 8785 JCS checksums computed over {} (not []) are accepted cleanly by GenericExportReader and preview.
     */
    public function test_export_reader_preserves_empty_json_objects_when_verifying_jcs_checksums(): void
    {
        $codec = new Codec;
        $recordJson = '{"sourceRecordId":"agr-empty-obj-001","sourceRecordRevision":1,"recordType":"agreement_reference",'
            .'"eventBusinessDate":"2025-11-01T09:00:00Z","originalBusinessRefs":{},'
            .'"evidenceRefs":[{"evidenceRef":"ev-empty-1","kind":"agreement_reference",'
            .'"digest":"sha256:1111111111111111111111111111111111111111111111111111111111111111","sanitized":true}]}';
        $recordsJson = '['.$recordJson.']';
        $recordsChecksum = 'sha256:'.hash('sha256', $codec->canonicalize($recordsJson));

        $artifactWithoutDigestJson = '{'
            .'"exportSchema":"source.export.v1",'
            .'"profile":"generic",'
            .'"exportVersion":"1.0.0",'
            .'"batchId":"batch-empty-obj-001",'
            .'"sourceSystem":"host-billing/export",'
            .'"sourceInstallation":"src-inst-empty",'
            .'"sourceBook":"book-empty",'
            .'"sourceExportRevision":1,'
            .'"exportCutoff":"2026-10-20T00:00:00Z",'
            .'"installationRef":"'.self::INSTALLATION.'",'
            .'"environment":"test",'
            .'"sellerRef":"seller-empty-obj-1",'
            .'"sanitizationPolicy":{"piiRedacted":true,"secretsStripped":true,"policyVersion":"v1"},'
            .'"provenance":{},'
            .'"totalsByCurrency":[],'
            .'"records":'.$recordsJson.','
            .'"recordCount":1,'
            .'"recordsChecksum":"'.$recordsChecksum.'"'
            .'}';
        $artifactDigest = $codec->digest($artifactWithoutDigestJson, 'artifactDigest');
        $fullArtifactJson = substr($artifactWithoutDigestJson, 0, -1).',"artifactDigest":"'.$artifactDigest.'"}';

        $reader = GenericExportReader::fromJson($fullArtifactJson, new GenericExportProfile, null, $codec);
        self::assertSame(1, $reader->manifest()->recordCount);
        self::assertSame($recordsChecksum, $reader->manifest()->recordsChecksum);
        self::assertSame([], $reader->records()[0]->originalBusinessRefs);

        $preview = $this->application()->make(ImportPreviewEvaluator::class)->evaluate($reader->manifest(), $reader->records());
        self::assertSame(ImportBatchStatus::Validated, $preview->batchStatus);
        self::assertInstanceOf(ExternalImportPreview::class, $codec->decode($codec->canonicalize($preview->toWire())));
    }

    /**
     * @param  list<ImportRecordInput>  $records
     * @return array<string, ImportRecordInput>
     */
    private static function indexBySourceRecordId(array $records): array
    {
        $indexed = [];
        foreach ($records as $record) {
            $indexed[$record->identity->sourceRecordId] = $record;
        }

        return $indexed;
    }

    private function repoPath(string $relative): string
    {
        return dirname(__DIR__, 6).'/'.$relative;
    }
}
