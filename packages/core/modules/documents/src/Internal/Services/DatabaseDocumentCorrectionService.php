<?php

declare(strict_types=1);

namespace Billium\Documents\Internal\Services;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Documents\Contracts\DocumentCorrectionService;
use Billium\Documents\Contracts\DocumentProfileRegistry;
use Billium\Documents\Contracts\DocumentsNumberingParticipant;
use Billium\Documents\Data\AllocateLegalNumberCommand;
use Billium\Documents\Data\DocumentCorrectionView;
use Billium\Documents\Data\DocumentCreditNoteLineView;
use Billium\Documents\Data\DocumentScope;
use Billium\Documents\Data\DocumentTaxCorrectionPolicy;
use Billium\Documents\Data\IssueDocumentCreditNote;
use Billium\Documents\Data\IssuePositiveDocumentCorrection;
use Billium\Documents\Data\RecordDocumentWriteOff;
use Billium\Documents\Data\VoidAndReissueLegalDocument;
use Billium\Documents\Enums\CorrectionKind;
use Billium\Documents\Enums\DocumentType;
use Billium\Documents\Enums\FinalizationState;
use Billium\Documents\Enums\LegalDocumentStatus;
use Billium\Documents\Enums\TaxCorrectionTreatment;
use Billium\Documents\Internal\Canonical\CanonicalJson;
use Billium\Documents\Internal\DocumentReceipt;
use Billium\Documents\Internal\Support\RowCast;
use Billium\Documents\Internal\Tax\PartialCreditAllocator;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Data\Invoices\CreditNoteLine;
use Billium\Ledger\Data\Invoices\IssueCreditNote;
use Billium\Ledger\Data\Obligations\RecordObligation;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class DatabaseDocumentCorrectionService implements DocumentCorrectionService
{
    public function __construct(
        private DatabaseManager $databases,
        private OperationRunner $runner,
        private CallerContext $callers,
        private LedgerParticipants $ledgerParticipants,
        private DocumentsNumberingParticipant $numberingParticipant,
        private DocumentProfileRegistry $profiles,
        private PartialCreditAllocator $allocator,
    ) {}

    public function issueCreditNote(IssueDocumentCreditNote $command): StoredOutcome
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->correctionRef, 'correctionRef');
        RowCast::bounded($command->creditNoteRef, 'creditNoteRef');
        RowCast::bounded($command->originalLegalDocumentRef, 'originalLegalDocumentRef');
        RowCast::bounded($command->invoiceRef, 'invoiceRef');
        RowCast::bounded($command->reason, 'reason', 500);
        if ($command->lines === []) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'credit note requires at least one line');
        }
        $this->allocator->validatePolicy($command->taxPolicy);

        if ($command->issueLegalDocument && ($command->seriesRef === null || $command->periodKey === null)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'legal credit note requires seriesRef and periodKey');
        }

        $lineRequests = [];
        $seenLines = [];
        foreach ($command->lines as $req) {
            RowCast::bounded($req->originalInvoiceLineId, 'originalInvoiceLineId');
            if (isset($seenLines[$req->originalInvoiceLineId])) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'duplicate originalInvoiceLineId');
            }
            $seenLines[$req->originalInvoiceLineId] = true;
            $lineRequests[] = [
                'originalInvoiceLineId' => $req->originalInvoiceLineId,
                'creditedQuantity' => $req->creditedQuantity,
                'requestedGrossMinor' => $req->requestedGrossMinor,
                'taxCode' => $req->taxCode,
            ];
        }

        $policyPayload = self::serializePolicy($command->taxPolicy);
        $digest = CanonicalJson::digest([
            'scope' => ['sellerRef' => $caller->sellerRef, 'environment' => $caller->environment, 'account' => $command->scope->billingAccountRef],
            'correctionRef' => $command->correctionRef,
            'creditNoteRef' => $command->creditNoteRef,
            'originalLegalDocumentRef' => $command->originalLegalDocumentRef,
            'invoiceRef' => $command->invoiceRef,
            'mode' => $command->mode->value,
            'lines' => $lineRequests,
            'policy' => $policyPayload,
            'reason' => $command->reason,
            'issueLegalDocument' => $command->issueLegalDocument,
            'seriesRef' => $command->seriesRef,
            'periodKey' => $command->periodKey,
        ]);

        $docScope = new DocumentScope($caller->sellerRef, $caller->environment);

        return $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'documents.correction.credit_note.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Active,
                operationClass: ModuleOperationClass::NewIntent,
                modules: ['platform', 'ledger', 'documents'],
                commandKey: $command->commandKey,
                digest: $digest,
                commitKind: 'documents.correction.credit_note_issued',
            ),
            body: function (TransactionContext $context) use ($caller, $command, $docScope, $policyPayload): DocumentReceipt {
                $this->runner->assertCurrent($context, 'documents');
                $db = $this->databases->connection($context->connectionName());
                $now = self::nowUtc();

                $origRow = $db->table('billium_document_legal')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('legal_document_ref', $command->originalLegalDocumentRef)
                    ->first();

                if ($origRow === null) {
                    throw new ProblemException(ProblemCode::AuthDenied, 'billium.documents.original_document_not_in_scope');
                }
                if (RowCast::str($origRow->economic_ref, 'economic_ref') !== $command->invoiceRef) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.invoice_ref_mismatch');
                }

                $original = DatabaseLegalDocumentIssuer::hydrate($origRow);
                $currencyObj = Currency::of($original->currency);
                $originalRounding = is_string($original->termsSnapshot['roundingPolicyVersion'] ?? null)
                    ? $original->termsSnapshot['roundingPolicyVersion']
                    : 'half_up/1';
                $taxSnapshotRef = is_string($original->taxSnapshot['taxSnapshotRef'] ?? null)
                    ? $original->taxSnapshot['taxSnapshotRef']
                    : 'tsnap-orig';

                /** @var array<string, array<string, mixed>> $origLineMap */
                $origLineMap = [];
                foreach ($original->linesSnapshot as $snapLine) {
                    $lineRef = RowCast::str($snapLine['lineRef'] ?? null, 'lineRef');
                    $origLineMap[$lineRef] = $snapLine;
                }

                // Load prior cumulative credits per original_invoice_line_id.
                $priorRows = $db->table('billium_document_credit_note_line')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('original_legal_document_id', $original->id)
                    ->get();

                $priorByLine = [];
                foreach ($priorRows as $pr) {
                    $lineId = RowCast::str($pr->original_invoice_line_id, 'original_invoice_line_id');
                    $priorByLine[$lineId] = [
                        'net' => ($priorByLine[$lineId]['net'] ?? 0) + RowCast::integer($pr->net_minor, 'net_minor'),
                        'tax' => ($priorByLine[$lineId]['tax'] ?? 0) + RowCast::integer($pr->tax_minor, 'tax_minor'),
                        'gross' => ($priorByLine[$lineId]['gross'] ?? 0) + RowCast::integer($pr->gross_minor, 'gross_minor'),
                    ];
                }

                $ledgerLines = [];
                $docLinesToInsert = [];
                $totalNet = 0;
                $totalTax = 0;
                $totalGross = 0;

                foreach ($command->lines as $req) {
                    if (! isset($origLineMap[$req->originalInvoiceLineId])) {
                        throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.unknown_original_line: '.$req->originalInvoiceLineId);
                    }
                    $origLine = $origLineMap[$req->originalInvoiceLineId];
                    $obRef = RowCast::str($origLine['obligationRef'] ?? null, 'obligationRef');
                    $compRef = RowCast::str($origLine['componentRef'] ?? null, 'componentRef');
                    $origNet = RowCast::integer($origLine['netMinor'] ?? null, 'netMinor');
                    $origTax = RowCast::integer($origLine['taxMinor'] ?? null, 'taxMinor');
                    $origGross = RowCast::integer($origLine['grossMinor'] ?? null, 'grossMinor');
                    $priorNet = $priorByLine[$req->originalInvoiceLineId]['net'] ?? 0;
                    $priorTax = $priorByLine[$req->originalInvoiceLineId]['tax'] ?? 0;
                    $priorGross = $priorByLine[$req->originalInvoiceLineId]['gross'] ?? 0;

                    $allocated = $this->allocator->allocateLine(
                        request: $req,
                        origNetMinor: $origNet,
                        origTaxMinor: $origTax,
                        origGrossMinor: $origGross,
                        priorNetMinor: $priorNet,
                        priorTaxMinor: $priorTax,
                        priorGrossMinor: $priorGross,
                        originalRoundingPolicyVersion: $originalRounding,
                        currency: $original->currency,
                        currencyExponent: $currencyObj->scale,
                        policy: $command->taxPolicy,
                    );

                    $totalNet += $allocated['netMinor'];
                    $totalTax += $allocated['taxMinor'];
                    $totalGross += $allocated['grossMinor'];

                    $ledgerLines[] = new CreditNoteLine(
                        obligationRef: $obRef,
                        money: new Money($allocated['grossMinor'], $currencyObj),
                        originalInvoiceLineId: $req->originalInvoiceLineId,
                        originalComponentRef: $compRef,
                        originalTaxSnapshotRef: $taxSnapshotRef,
                        originalRoundingPolicyVersion: $originalRounding,
                        creditedQuantity: $req->creditedQuantity,
                    );

                    $docLinesToInsert[] = [
                        'seller_ref' => $caller->sellerRef,
                        'environment' => $caller->environment,
                        'original_legal_document_id' => $original->id,
                        'original_invoice_line_id' => $req->originalInvoiceLineId,
                        'original_obligation_ref' => $obRef,
                        'original_component_ref' => $compRef,
                        'original_tax_snapshot_ref' => $taxSnapshotRef,
                        'original_rounding_policy_version' => $originalRounding,
                        'credited_quantity' => $req->creditedQuantity,
                        'net_minor' => $allocated['netMinor'],
                        'tax_minor' => $allocated['taxMinor'],
                        'gross_minor' => $allocated['grossMinor'],
                        'currency' => $original->currency,
                        'cumulative_net_minor' => $priorNet + $allocated['netMinor'],
                        'cumulative_tax_minor' => $priorTax + $allocated['taxMinor'],
                        'cumulative_gross_minor' => $priorGross + $allocated['grossMinor'],
                        'original_basis_net_minor' => $origNet,
                        'original_basis_tax_minor' => $origTax,
                        'original_basis_gross_minor' => $origGross,
                        'created_at' => $now,
                    ];
                }

                $resultingLegalRef = null;
                $resultingSeries = null;
                $profile = null;
                if ($command->issueLegalDocument) {
                    $seriesRef = $command->seriesRef ?? '';
                    $periodKey = $command->periodKey ?? '';
                    $resultingSeries = $db->table('billium_document_series')
                        ->where('seller_ref', $caller->sellerRef)
                        ->where('environment', $caller->environment)
                        ->where('series_ref', $seriesRef)
                        ->where('period_key', $periodKey)
                        ->first();
                    if ($resultingSeries === null) {
                        throw new ProblemException(ProblemCode::AuthDenied, 'billium.documents.series_not_in_scope');
                    }
                    if (! RowCast::boolean($resultingSeries->active, 'active')
                        || RowCast::str($resultingSeries->document_type, 'document_type') !== DocumentType::CreditNote->value) {
                        throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.series_document_type_mismatch');
                    }
                    $finRow = $db->table('billium_document_finalization')
                        ->where('id', $original->finalizationId)
                        ->first();
                    if ($finRow === null) {
                        throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.documents.finalization_not_found');
                    }
                    $profile = $this->profiles->requireComplete(
                        $docScope,
                        RowCast::str($finRow->profile_ref, 'profile_ref'),
                        $original->profileVersion,
                        DocumentType::CreditNote,
                    );
                    $resultingLegalRef = self::deterministicRef($caller->sellerRef, $caller->environment, 'credit_note', $command->creditNoteRef, 1);
                }

                // LockRank <= 90 via LedgerParticipants::issueCreditNote (strictly before DocumentSeries 130).
                $this->ledgerParticipants->issueCreditNote(
                    $context,
                    new IssueCreditNote(
                        commandKey: $command->commandKey,
                        scope: $command->scope,
                        creditNoteRef: $command->creditNoteRef,
                        invoiceRef: $command->invoiceRef,
                        mode: $command->mode,
                        lines: $ledgerLines,
                        reason: $command->reason,
                        legalDocumentRef: $resultingLegalRef,
                    ),
                );

                $resultingDoc = null;
                if ($command->issueLegalDocument) {
                    $resultingDoc = $this->numberingParticipant->allocateAndRecord($context, new AllocateLegalNumberCommand(
                        legalDocumentRef: $resultingLegalRef,
                        documentType: DocumentType::CreditNote,
                        economicRef: $command->creditNoteRef,
                        documentRevision: 1,
                        seriesId: RowCast::integer($resultingSeries->id, 'id'),
                        profileId: $profile->id,
                        profileVersion: $profile->version,
                        finalizationId: $original->finalizationId,
                        basisRevision: $original->basisRevision,
                        basisDigest: $original->basisDigest,
                        approvedSnapshotDigest: $command->taxPolicy->outputDigest,
                        currency: $original->currency,
                        subtotalMinor: $totalNet,
                        taxMinor: $totalTax,
                        totalMinor: $totalGross,
                        sellerSnapshot: $original->sellerSnapshot,
                        buyerSnapshot: $original->buyerSnapshot,
                        taxSnapshot: $policyPayload,
                        termsSnapshot: $original->termsSnapshot,
                        linesSnapshot: $docLinesToInsert,
                        displayMetadata: ['displayLabel' => 'Credit Note for '.$original->legalNumber],
                        receiptRef: 'legal-credit:'.$resultingLegalRef,
                        originalLegalDocumentRef: $original->legalDocumentRef,
                    ));
                }

                $correctionId = $db->table('billium_document_correction')->insertGetId([
                    'seller_ref' => $caller->sellerRef,
                    'environment' => $caller->environment,
                    'correction_ref' => $command->correctionRef,
                    'correction_kind' => CorrectionKind::CreditNote->value,
                    'original_legal_document_id' => $original->id,
                    'original_legal_document_ref' => $original->legalDocumentRef,
                    'resulting_legal_document_id' => $resultingDoc?->id,
                    'resulting_legal_document_ref' => $resultingDoc?->legalDocumentRef,
                    'ledger_credit_note_ref' => $command->creditNoteRef,
                    'ledger_obligation_ref' => null,
                    'parent_occurrence_ref' => null,
                    'correction_revision' => 1,
                    'tax_correction_policy_ref' => $command->taxPolicy->policyRef,
                    'tax_correction_policy_version' => $command->taxPolicy->policyVersion,
                    'tax_treatment' => $command->taxPolicy->treatment->value,
                    'tax_policy_snapshot' => CanonicalJson::encodeObject($policyPayload),
                    'currency' => $original->currency,
                    'net_minor' => $totalNet,
                    'tax_minor' => $totalTax,
                    'gross_minor' => $totalGross,
                    'reason' => $command->reason,
                    'allocation_mode' => $command->mode->value,
                    'receipt_ref' => 'doc-corr:'.$command->correctionRef,
                    'created_at' => $now,
                ]);

                foreach ($docLinesToInsert as $lineRow) {
                    $db->table('billium_document_credit_note_line')->insert([
                        ...$lineRow,
                        'credit_note_id' => $correctionId,
                    ]);
                }

                return new DocumentReceipt('documents.correction.credit_note_issued', [
                    'correctionRef' => $command->correctionRef,
                    'creditNoteRef' => $command->creditNoteRef,
                    'netMinor' => $totalNet,
                    'taxMinor' => $totalTax,
                    'grossMinor' => $totalGross,
                    'resultingLegalDocumentRef' => $resultingDoc?->legalDocumentRef,
                    'resultingLegalNumber' => $resultingDoc?->legalNumber,
                ]);
            },
        );
    }

    public function issuePositiveCorrection(IssuePositiveDocumentCorrection $command): StoredOutcome
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->correctionRef, 'correctionRef');
        RowCast::bounded($command->originalLegalDocumentRef, 'originalLegalDocumentRef');
        RowCast::bounded($command->obligationRef, 'obligationRef');
        RowCast::bounded($command->customerRef, 'customerRef');
        RowCast::bounded($command->componentRef, 'componentRef');
        RowCast::bounded($command->parentOccurrenceRef, 'parentOccurrenceRef');
        RowCast::bounded($command->reason, 'reason', 500);
        if ($command->deltaNetMinor < 0 || $command->deltaTaxMinor < 0 || ($command->deltaNetMinor + $command->deltaTaxMinor) <= 0 || $command->correctionRevision < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'positive correction deltas and revision must be valid');
        }
        $this->allocator->validatePolicy($command->taxPolicy);

        $currencyObj = Currency::of($command->currency);
        $grossDelta = $command->deltaNetMinor + $command->deltaTaxMinor;
        $policyPayload = self::serializePolicy($command->taxPolicy);
        $digest = CanonicalJson::digest([
            'scope' => ['sellerRef' => $caller->sellerRef, 'environment' => $caller->environment, 'account' => $command->scope->billingAccountRef],
            'correctionRef' => $command->correctionRef,
            'originalLegalDocumentRef' => $command->originalLegalDocumentRef,
            'obligationRef' => $command->obligationRef,
            'parentOccurrenceRef' => $command->parentOccurrenceRef,
            'correctionRevision' => $command->correctionRevision,
            'deltaNetMinor' => $command->deltaNetMinor,
            'deltaTaxMinor' => $command->deltaTaxMinor,
            'currency' => $currencyObj->code,
            'policy' => $policyPayload,
        ]);

        return $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'documents.correction.positive.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Active,
                operationClass: ModuleOperationClass::NewIntent,
                modules: ['platform', 'ledger', 'documents'],
                commandKey: $command->commandKey,
                digest: $digest,
                commitKind: 'documents.correction.positive_issued',
            ),
            body: function (TransactionContext $context) use ($caller, $command, $currencyObj, $grossDelta, $policyPayload, $digest): DocumentReceipt {
                $this->runner->assertCurrent($context, 'documents');
                $db = $this->databases->connection($context->connectionName());
                $now = self::nowUtc();

                $origRow = $db->table('billium_document_legal')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('legal_document_ref', $command->originalLegalDocumentRef)
                    ->first();

                if ($origRow === null) {
                    throw new ProblemException(ProblemCode::AuthDenied, 'billium.documents.original_document_not_in_scope');
                }
                $original = DatabaseLegalDocumentIssuer::hydrate($origRow);
                if ($original->currency !== $currencyObj->code) {
                    throw new ProblemException(ProblemCode::MoneyCurrencyMismatch, 'correction currency differs from original document');
                }

                // Positive issued-period delta creates a NEW linked obligation (never adjustObligation on frozen original).
                $this->ledgerParticipants->recordObligation($context, new RecordObligation(
                    commandKey: $command->commandKey,
                    scope: $command->scope,
                    obligationRef: $command->obligationRef,
                    basisRef: 'corr:'.$command->parentOccurrenceRef.':r'.$command->correctionRevision,
                    customerRef: $command->customerRef,
                    componentRef: $command->componentRef,
                    money: new Money($grossDelta, $currencyObj),
                    termsDigest: $digest,
                ));

                $resultingDoc = null;
                if ($command->issueLegalDocument && $command->seriesRef !== null && $command->periodKey !== null && $command->finalizationRef !== null && $command->approvedSnapshotDigest !== null) {
                    $series = $db->table('billium_document_series')
                        ->where('seller_ref', $caller->sellerRef)
                        ->where('environment', $caller->environment)
                        ->where('series_ref', $command->seriesRef)
                        ->where('period_key', $command->periodKey)
                        ->first();
                    if ($series === null || RowCast::str($series->document_type, 'document_type') !== DocumentType::Correction->value) {
                        throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.correction_series_invalid');
                    }
                    $fin = $db->table('billium_document_finalization')
                        ->where('seller_ref', $caller->sellerRef)
                        ->where('environment', $caller->environment)
                        ->where('finalization_ref', $command->finalizationRef)
                        ->where('state', FinalizationState::SnapshotReady->value)
                        ->where('approved_snapshot_digest', $command->approvedSnapshotDigest)
                        ->first();
                    if ($fin === null) {
                        throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.snapshot_not_ready');
                    }
                    $profile = $this->profiles->requireComplete(
                        new DocumentScope($caller->sellerRef, $caller->environment),
                        RowCast::str($fin->profile_ref, 'profile_ref'),
                        RowCast::integer($fin->profile_version, 'profile_version'),
                        DocumentType::Correction,
                    );
                    $resultingLegalRef = self::deterministicRef($caller->sellerRef, $caller->environment, 'correction', $command->correctionRef, $command->correctionRevision);
                    $resultingDoc = $this->numberingParticipant->allocateAndRecord($context, new AllocateLegalNumberCommand(
                        legalDocumentRef: $resultingLegalRef,
                        documentType: DocumentType::Correction,
                        economicRef: $command->obligationRef,
                        documentRevision: $command->correctionRevision,
                        seriesId: RowCast::integer($series->id, 'id'),
                        profileId: $profile->id,
                        profileVersion: $profile->version,
                        finalizationId: RowCast::integer($fin->id, 'id'),
                        basisRevision: $command->correctionRevision,
                        basisDigest: $original->basisDigest,
                        approvedSnapshotDigest: $command->approvedSnapshotDigest,
                        currency: $currencyObj->code,
                        subtotalMinor: $command->deltaNetMinor,
                        taxMinor: $command->deltaTaxMinor,
                        totalMinor: $grossDelta,
                        sellerSnapshot: $profile->sellerSnapshot,
                        buyerSnapshot: $original->buyerSnapshot,
                        taxSnapshot: $policyPayload,
                        termsSnapshot: $original->termsSnapshot,
                        linesSnapshot: [[
                            'lineRef' => 'corr-line-1',
                            'obligationRef' => $command->obligationRef,
                            'componentRef' => $command->componentRef,
                            'netMinor' => $command->deltaNetMinor,
                            'taxMinor' => $command->deltaTaxMinor,
                            'grossMinor' => $grossDelta,
                        ]],
                        displayMetadata: ['displayLabel' => 'Correction for '.$original->legalNumber],
                        receiptRef: 'legal-corr:'.$resultingLegalRef,
                        originalLegalDocumentRef: $original->legalDocumentRef,
                    ));
                }

                $db->table('billium_document_correction')->insert([
                    'seller_ref' => $caller->sellerRef,
                    'environment' => $caller->environment,
                    'correction_ref' => $command->correctionRef,
                    'correction_kind' => CorrectionKind::DocumentCorrection->value,
                    'original_legal_document_id' => $original->id,
                    'original_legal_document_ref' => $original->legalDocumentRef,
                    'resulting_legal_document_id' => $resultingDoc?->id,
                    'resulting_legal_document_ref' => $resultingDoc?->legalDocumentRef,
                    'ledger_credit_note_ref' => null,
                    'ledger_obligation_ref' => $command->obligationRef,
                    'parent_occurrence_ref' => $command->parentOccurrenceRef,
                    'correction_revision' => $command->correctionRevision,
                    'tax_correction_policy_ref' => $command->taxPolicy->policyRef,
                    'tax_correction_policy_version' => $command->taxPolicy->policyVersion,
                    'tax_treatment' => $command->taxPolicy->treatment->value,
                    'tax_policy_snapshot' => CanonicalJson::encodeObject($policyPayload),
                    'currency' => $currencyObj->code,
                    'net_minor' => $command->deltaNetMinor,
                    'tax_minor' => $command->deltaTaxMinor,
                    'gross_minor' => $grossDelta,
                    'reason' => $command->reason,
                    'allocation_mode' => null,
                    'receipt_ref' => 'doc-corr:'.$command->correctionRef,
                    'created_at' => $now,
                ]);

                return new DocumentReceipt('documents.correction.positive_issued', [
                    'correctionRef' => $command->correctionRef,
                    'obligationRef' => $command->obligationRef,
                    'grossMinor' => $grossDelta,
                    'resultingLegalDocumentRef' => $resultingDoc?->legalDocumentRef,
                    'resultingLegalNumber' => $resultingDoc?->legalNumber,
                ]);
            },
        );
    }

    public function voidAndReissue(VoidAndReissueLegalDocument $command): StoredOutcome
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->correctionRef, 'correctionRef');
        RowCast::bounded($command->originalLegalDocumentRef, 'originalLegalDocumentRef');
        RowCast::bounded($command->newFinalizationRef, 'newFinalizationRef');
        RowCast::bounded($command->approvedSnapshotDigest, 'approvedSnapshotDigest');
        RowCast::bounded($command->seriesRef, 'seriesRef');
        RowCast::bounded($command->periodKey, 'periodKey', 32);
        RowCast::bounded($command->reason, 'reason', 500);

        $digest = CanonicalJson::digest([
            'scope' => ['sellerRef' => $caller->sellerRef, 'environment' => $caller->environment],
            'correctionRef' => $command->correctionRef,
            'originalLegalDocumentRef' => $command->originalLegalDocumentRef,
            'newFinalizationRef' => $command->newFinalizationRef,
            'approvedSnapshotDigest' => $command->approvedSnapshotDigest,
            'seriesRef' => $command->seriesRef,
            'periodKey' => $command->periodKey,
            'reason' => $command->reason,
        ]);

        return $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'documents.correction.void_reissue.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Administrative,
                operationClass: ModuleOperationClass::NewIntent,
                modules: ['platform', 'ledger', 'documents'],
                commandKey: $command->commandKey,
                digest: $digest,
            ),
            body: function (TransactionContext $context) use ($caller, $command): DocumentReceipt {
                $this->runner->assertCurrent($context, 'documents');
                $db = $this->databases->connection($context->connectionName());
                $now = self::nowUtc();

                $origRow = $db->table('billium_document_legal')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('legal_document_ref', $command->originalLegalDocumentRef)
                    ->first();

                if ($origRow === null) {
                    throw new ProblemException(ProblemCode::AuthDenied, 'billium.documents.original_document_not_in_scope');
                }
                $original = DatabaseLegalDocumentIssuer::hydrate($origRow);
                if ($original->status === LegalDocumentStatus::Voided) {
                    throw new ProblemException(ProblemCode::InvoiceTransitionInvalid, 'billium.documents.original_already_voided');
                }

                $finRow = $db->table('billium_document_finalization')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('finalization_ref', $command->newFinalizationRef)
                    ->where('state', FinalizationState::SnapshotReady->value)
                    ->where('approved_snapshot_digest', $command->approvedSnapshotDigest)
                    ->first();

                if ($finRow === null) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.snapshot_not_ready');
                }
                $finalization = DatabaseDocumentFinalizationService::hydrate($finRow);
                $profile = $this->profiles->requireComplete(
                    $command->scope,
                    RowCast::str($finRow->profile_ref, 'profile_ref'),
                    $finalization->profileVersion,
                    $original->documentType,
                );

                $series = $db->table('billium_document_series')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('series_ref', $command->seriesRef)
                    ->where('period_key', $command->periodKey)
                    ->first();

                if ($series === null || ! RowCast::boolean($series->active, 'active')) {
                    throw new ProblemException(ProblemCode::AuthDenied, 'billium.documents.series_not_in_scope');
                }

                // Step 1: Void original legal document (number preserved in journal and never reused).
                $db->table('billium_document_legal')
                    ->where('id', $original->id)
                    ->where('status', LegalDocumentStatus::Issued->value)
                    ->update([
                        'status' => LegalDocumentStatus::Voided->value,
                        'void_reason' => $command->reason,
                        'voided_at' => $now,
                    ]);

                $db->table('billium_document_number_journal')->insert([
                    'seller_ref' => $caller->sellerRef,
                    'environment' => $caller->environment,
                    'series_ref' => $original->seriesRef,
                    'period_key' => $original->periodKey,
                    'sequence_number' => $original->sequenceNumber,
                    'legal_number' => $original->legalNumber,
                    'legal_document_ref' => $original->legalDocumentRef,
                    'event_kind' => 'voided',
                    'reason' => $command->reason,
                    'occurred_at' => $now,
                ]);

                // Step 2: Allocate new revision legal document linked to voided original.
                $newRevision = $original->documentRevision + 1;
                $newLegalRef = self::deterministicRef($caller->sellerRef, $caller->environment, 'reissue', $original->economicRef, $newRevision);
                $taxPayload = $finalization->taxSnapshot !== null
                    ? DatabaseDocumentFinalizationService::serializeSnapshot($finalization->taxSnapshot)
                    : [];

                $reissued = $this->numberingParticipant->allocateAndRecord($context, new AllocateLegalNumberCommand(
                    legalDocumentRef: $newLegalRef,
                    documentType: $original->documentType,
                    economicRef: $original->economicRef,
                    documentRevision: $newRevision,
                    seriesId: RowCast::integer($series->id, 'id'),
                    profileId: $profile->id,
                    profileVersion: $profile->version,
                    finalizationId: $finalization->id,
                    basisRevision: $finalization->basisRevision,
                    basisDigest: $finalization->basisDigest,
                    approvedSnapshotDigest: $command->approvedSnapshotDigest,
                    currency: $finalization->currency,
                    subtotalMinor: $finalization->subtotalMinor ?? 0,
                    taxMinor: $finalization->taxMinor ?? 0,
                    totalMinor: $finalization->totalMinor ?? 0,
                    sellerSnapshot: $profile->sellerSnapshot,
                    buyerSnapshot: $finalization->buyerSnapshot,
                    taxSnapshot: $taxPayload,
                    termsSnapshot: $finalization->termsSnapshot,
                    linesSnapshot: $original->linesSnapshot,
                    displayMetadata: ['displayLabel' => 'Reissue of '.$original->legalNumber],
                    receiptRef: 'legal-reissue:'.$newLegalRef,
                    originalLegalDocumentRef: $original->legalDocumentRef,
                ));

                $db->table('billium_document_correction')->insert([
                    'seller_ref' => $caller->sellerRef,
                    'environment' => $caller->environment,
                    'correction_ref' => $command->correctionRef,
                    'correction_kind' => CorrectionKind::VoidReissue->value,
                    'original_legal_document_id' => $original->id,
                    'original_legal_document_ref' => $original->legalDocumentRef,
                    'resulting_legal_document_id' => $reissued->id,
                    'resulting_legal_document_ref' => $reissued->legalDocumentRef,
                    'ledger_credit_note_ref' => null,
                    'ledger_obligation_ref' => null,
                    'parent_occurrence_ref' => null,
                    'correction_revision' => $newRevision,
                    'tax_correction_policy_ref' => 'void-reissue',
                    'tax_correction_policy_version' => 'v1',
                    'tax_treatment' => TaxCorrectionTreatment::OriginalRate->value,
                    'tax_policy_snapshot' => '{}',
                    'currency' => $reissued->currency,
                    'net_minor' => $reissued->subtotalMinor,
                    'tax_minor' => $reissued->taxMinor,
                    'gross_minor' => $reissued->totalMinor,
                    'reason' => $command->reason,
                    'allocation_mode' => null,
                    'receipt_ref' => 'doc-corr:'.$command->correctionRef,
                    'created_at' => $now,
                ]);

                return new DocumentReceipt('documents.correction.void_reissued', [
                    'correctionRef' => $command->correctionRef,
                    'originalLegalDocumentRef' => $original->legalDocumentRef,
                    'resultingLegalDocumentRef' => $reissued->legalDocumentRef,
                    'resultingLegalNumber' => $reissued->legalNumber,
                ]);
            },
        );
    }

    public function recordWriteOff(RecordDocumentWriteOff $command): StoredOutcome
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->correctionRef, 'correctionRef');
        RowCast::bounded($command->originalLegalDocumentRef, 'originalLegalDocumentRef');
        RowCast::bounded($command->reason, 'reason', 500);
        if ($command->amountMinor <= 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'write-off amount must be positive');
        }
        $currencyObj = Currency::of($command->currency);

        $digest = CanonicalJson::digest([
            'scope' => ['sellerRef' => $caller->sellerRef, 'environment' => $caller->environment],
            'correctionRef' => $command->correctionRef,
            'originalLegalDocumentRef' => $command->originalLegalDocumentRef,
            'amountMinor' => $command->amountMinor,
            'currency' => $currencyObj->code,
            'reason' => $command->reason,
        ]);

        return $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'documents.correction.write_off.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Administrative,
                operationClass: ModuleOperationClass::NewIntent,
                modules: ['platform', 'ledger', 'documents'],
                commandKey: $command->commandKey,
                digest: $digest,
            ),
            body: function (TransactionContext $context) use ($caller, $command, $currencyObj): DocumentReceipt {
                $this->runner->assertCurrent($context, 'documents');
                $db = $this->databases->connection($context->connectionName());
                $origRow = $db->table('billium_document_legal')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('legal_document_ref', $command->originalLegalDocumentRef)
                    ->first();

                if ($origRow === null) {
                    throw new ProblemException(ProblemCode::AuthDenied, 'billium.documents.original_document_not_in_scope');
                }
                if (RowCast::str($origRow->currency, 'currency') !== $currencyObj->code) {
                    throw new ProblemException(ProblemCode::MoneyCurrencyMismatch, 'write-off currency mismatch');
                }
                if ($command->amountMinor > RowCast::integer($origRow->total_minor, 'total_minor')) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'write-off exceeds original document total');
                }

                $db->table('billium_document_correction')->insert([
                    'seller_ref' => $caller->sellerRef,
                    'environment' => $caller->environment,
                    'correction_ref' => $command->correctionRef,
                    'correction_kind' => CorrectionKind::WriteOff->value,
                    'original_legal_document_id' => RowCast::integer($origRow->id, 'id'),
                    'original_legal_document_ref' => $command->originalLegalDocumentRef,
                    'resulting_legal_document_id' => null,
                    'resulting_legal_document_ref' => null,
                    'ledger_credit_note_ref' => null,
                    'ledger_obligation_ref' => null,
                    'parent_occurrence_ref' => null,
                    'correction_revision' => 1,
                    'tax_correction_policy_ref' => 'write-off',
                    'tax_correction_policy_version' => 'v1',
                    'tax_treatment' => TaxCorrectionTreatment::OriginalRate->value,
                    'tax_policy_snapshot' => '{}',
                    'currency' => $currencyObj->code,
                    'net_minor' => $command->amountMinor,
                    'tax_minor' => 0,
                    'gross_minor' => $command->amountMinor,
                    'reason' => $command->reason,
                    'allocation_mode' => null,
                    'receipt_ref' => 'doc-writeoff:'.$command->correctionRef,
                    'created_at' => self::nowUtc(),
                ]);

                return new DocumentReceipt('documents.correction.write_off_recorded', [
                    'correctionRef' => $command->correctionRef,
                    'amountMinor' => $command->amountMinor,
                    'currency' => $currencyObj->code,
                ]);
            },
        );
    }

    public function findCorrection(DocumentScope $scope, string $correctionRef): ?DocumentCorrectionView
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);

        $row = $this->databases->connection()->table('billium_document_correction')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('correction_ref', $correctionRef)
            ->first();

        return $row === null ? null : self::hydrateCorrection($row);
    }

    /** @return list<DocumentCreditNoteLineView> */
    public function creditNoteLines(DocumentScope $scope, string $correctionRef): array
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);

        $rows = $this->databases->connection()->table('billium_document_credit_note_line as l')
            ->join('billium_document_correction as c', 'c.id', '=', 'l.credit_note_id')
            ->where('c.seller_ref', $caller->sellerRef)
            ->where('c.environment', $caller->environment)
            ->where('c.correction_ref', $correctionRef)
            ->orderBy('l.id')
            ->select('l.*')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = new DocumentCreditNoteLineView(
                id: RowCast::integer($row->id, 'id'),
                sellerRef: RowCast::str($row->seller_ref, 'seller_ref'),
                environment: RowCast::str($row->environment, 'environment'),
                creditNoteId: RowCast::integer($row->credit_note_id, 'credit_note_id'),
                originalInvoiceLineId: RowCast::str($row->original_invoice_line_id, 'original_invoice_line_id'),
                originalObligationRef: RowCast::str($row->original_obligation_ref, 'original_obligation_ref'),
                originalComponentRef: RowCast::str($row->original_component_ref, 'original_component_ref'),
                originalTaxSnapshotRef: RowCast::str($row->original_tax_snapshot_ref, 'original_tax_snapshot_ref'),
                originalRoundingPolicyVersion: RowCast::str($row->original_rounding_policy_version, 'original_rounding_policy_version'),
                creditedQuantity: RowCast::str($row->credited_quantity, 'credited_quantity'),
                netMinor: RowCast::integer($row->net_minor, 'net_minor'),
                taxMinor: RowCast::integer($row->tax_minor, 'tax_minor'),
                grossMinor: RowCast::integer($row->gross_minor, 'gross_minor'),
                currency: RowCast::str($row->currency, 'currency'),
                cumulativeNetMinor: RowCast::integer($row->cumulative_net_minor, 'cumulative_net_minor'),
                cumulativeTaxMinor: RowCast::integer($row->cumulative_tax_minor, 'cumulative_tax_minor'),
                cumulativeGrossMinor: RowCast::integer($row->cumulative_gross_minor, 'cumulative_gross_minor'),
                originalBasisNetMinor: RowCast::integer($row->original_basis_net_minor, 'original_basis_net_minor'),
                originalBasisTaxMinor: RowCast::integer($row->original_basis_tax_minor, 'original_basis_tax_minor'),
                originalBasisGrossMinor: RowCast::integer($row->original_basis_gross_minor, 'original_basis_gross_minor'),
            );
        }

        return $out;
    }

    public static function hydrateCorrection(stdClass $row): DocumentCorrectionView
    {
        return new DocumentCorrectionView(
            id: RowCast::integer($row->id, 'id'),
            sellerRef: RowCast::str($row->seller_ref, 'seller_ref'),
            environment: RowCast::str($row->environment, 'environment'),
            correctionRef: RowCast::str($row->correction_ref, 'correction_ref'),
            correctionKind: CorrectionKind::from(RowCast::str($row->correction_kind, 'correction_kind')),
            originalLegalDocumentRef: RowCast::str($row->original_legal_document_ref, 'original_legal_document_ref'),
            resultingLegalDocumentRef: RowCast::optStr($row->resulting_legal_document_ref, 'resulting_legal_document_ref'),
            ledgerCreditNoteRef: RowCast::optStr($row->ledger_credit_note_ref, 'ledger_credit_note_ref'),
            ledgerObligationRef: RowCast::optStr($row->ledger_obligation_ref, 'ledger_obligation_ref'),
            parentOccurrenceRef: RowCast::optStr($row->parent_occurrence_ref, 'parent_occurrence_ref'),
            correctionRevision: RowCast::integer($row->correction_revision, 'correction_revision'),
            taxCorrectionPolicyRef: RowCast::str($row->tax_correction_policy_ref, 'tax_correction_policy_ref'),
            taxCorrectionPolicyVersion: RowCast::str($row->tax_correction_policy_version, 'tax_correction_policy_version'),
            taxTreatment: TaxCorrectionTreatment::from(RowCast::str($row->tax_treatment, 'tax_treatment')),
            taxPolicySnapshot: RowCast::jsonObject($row->tax_policy_snapshot, 'tax_policy_snapshot'),
            currency: RowCast::str($row->currency, 'currency'),
            netMinor: RowCast::integer($row->net_minor, 'net_minor'),
            taxMinor: RowCast::integer($row->tax_minor, 'tax_minor'),
            grossMinor: RowCast::integer($row->gross_minor, 'gross_minor'),
            reason: RowCast::str($row->reason, 'reason'),
            allocationMode: RowCast::optStr($row->allocation_mode, 'allocation_mode'),
            receiptRef: RowCast::str($row->receipt_ref, 'receipt_ref'),
            createdAt: RowCast::timestamp($row->created_at, 'created_at'),
        );
    }

    /** @return array<string, mixed> */
    private static function serializePolicy(DocumentTaxCorrectionPolicy $policy): array
    {
        return [
            'policyRef' => $policy->policyRef,
            'policyVersion' => $policy->policyVersion,
            'treatment' => $policy->treatment->value,
            'selectedRateDecimal' => $policy->selectedRateDecimal,
            'taxPoint' => $policy->taxPoint,
            'roundingPolicyVersion' => $policy->roundingPolicyVersion,
            'inputDigest' => $policy->inputDigest,
            'outputDigest' => $policy->outputDigest,
            'resolverVersion' => $policy->resolverVersion,
        ];
    }

    private static function deterministicRef(string $sellerRef, string $environment, string $kind, string $ref, int $rev): string
    {
        return 'ldoc-'.substr(hash('sha256', CanonicalJson::encode([$sellerRef, $environment, $kind, $ref, $rev])), 0, 24);
    }

    private static function nowUtc(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
