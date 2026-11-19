<?php

declare(strict_types=1);

namespace Billium\Documents\Internal\Services;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Documents\Contracts\DocumentProfileRegistry;
use Billium\Documents\Contracts\DocumentsNumberingParticipant;
use Billium\Documents\Contracts\LedgerIssuePolicy;
use Billium\Documents\Contracts\LegalDocumentIssuer;
use Billium\Documents\Data\AllocateLegalNumberCommand;
use Billium\Documents\Data\DocumentFinalizationView;
use Billium\Documents\Data\DocumentProfile;
use Billium\Documents\Data\DocumentScope;
use Billium\Documents\Data\IssueLegalDocument;
use Billium\Documents\Data\LegalDocumentView;
use Billium\Documents\Data\UpdateLegalDocumentDisplayMetadata;
use Billium\Documents\Data\VoidLegalDocument;
use Billium\Documents\Enums\DocumentType;
use Billium\Documents\Enums\FinalizationState;
use Billium\Documents\Enums\LegalDocumentStatus;
use Billium\Documents\Internal\Canonical\CanonicalJson;
use Billium\Documents\Internal\DocumentReceipt;
use Billium\Documents\Internal\Support\RowCast;
use Billium\Ledger\Contracts\LockedInvoiceSnapshotParticipant;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class DatabaseLegalDocumentIssuer implements LegalDocumentIssuer
{
    /** @var list<string> */
    private const array ALLOWED_DISPLAY_METADATA_KEYS = [
        'displayLabel',
        'deliveryNote',
        'locale',
        'customNotes',
        'externalReference',
    ];

    public function __construct(
        private DatabaseManager $databases,
        private OperationRunner $runner,
        private CallerContext $callers,
        private DocumentProfileRegistry $profiles,
        private LedgerIssuePolicy $ledgerIssuePolicy,
        private LockedInvoiceSnapshotParticipant $lockedInvoiceSnapshots,
        private DocumentsNumberingParticipant $numberingParticipant,
    ) {}

    public function issueInvoiceDocument(IssueLegalDocument $command): StoredOutcome
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->invoiceRef, 'invoiceRef');
        RowCast::bounded($command->economicRef, 'economicRef');
        RowCast::bounded($command->finalizationRef, 'finalizationRef');
        RowCast::bounded($command->approvedSnapshotDigest, 'approvedSnapshotDigest');
        RowCast::bounded($command->seriesRef, 'seriesRef');
        RowCast::bounded($command->periodKey, 'periodKey', 32);
        if ($command->documentRevision < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'documentRevision must be positive');
        }
        self::assertDisplayMetadataAllowList($command->displayMetadata);

        $legalDocumentRef = $command->legalDocumentRef !== null
            ? RowCast::bounded($command->legalDocumentRef, 'legalDocumentRef')
            : self::deterministicLegalDocumentRef($caller->sellerRef, $caller->environment, DocumentType::Invoice, $command->economicRef, $command->documentRevision);

        $digest = CanonicalJson::digest([
            'scope' => [
                'sellerRef' => $command->scope->sellerRef,
                'environment' => $command->scope->environment,
                'billingAccountRef' => $command->scope->billingAccountRef,
            ],
            'invoiceRef' => $command->invoiceRef,
            'economicRef' => $command->economicRef,
            'documentRevision' => $command->documentRevision,
            'finalizationRef' => $command->finalizationRef,
            'approvedSnapshotDigest' => $command->approvedSnapshotDigest,
            'seriesRef' => $command->seriesRef,
            'periodKey' => $command->periodKey,
            'displayMetadata' => $command->displayMetadata,
            'legalDocumentRef' => $legalDocumentRef,
        ]);

        return $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'documents.legal_document.issue.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Active,
                operationClass: ModuleOperationClass::NewIntent,
                modules: ['platform', 'ledger', 'documents'],
                commandKey: $command->commandKey,
                digest: $digest,
                commitKind: 'documents.legal_document.issued',
            ),
            body: function (TransactionContext $context) use ($caller, $command, $legalDocumentRef): DocumentReceipt {
                $this->runner->assertCurrent($context, 'documents');
                $db = $this->databases->connection($context->connectionName());

                $finRow = $db->table('billium_document_finalization')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('finalization_ref', $command->finalizationRef)
                    ->where('state', '<>', FinalizationState::Superseded->value)
                    ->first();

                if ($finRow === null || RowCast::str($finRow->state, 'state') !== FinalizationState::SnapshotReady->value) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.snapshot_not_ready');
                }
                if (
                    RowCast::str($finRow->document_type, 'document_type') !== DocumentType::Invoice->value
                    || RowCast::str($finRow->economic_ref, 'economic_ref') !== $command->economicRef
                    || RowCast::optStr($finRow->approved_snapshot_digest, 'approved_snapshot_digest') !== $command->approvedSnapshotDigest
                ) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.snapshot_mismatch');
                }

                $finalization = DatabaseDocumentFinalizationService::hydrate($finRow);
                $profile = $this->profiles->requireComplete(
                    new DocumentScope($caller->sellerRef, $caller->environment),
                    RowCast::str($finRow->profile_ref, 'profile_ref'),
                    $finalization->profileVersion,
                    DocumentType::Invoice,
                );
                self::assertRequiredBuyerFields($profile, $finalization->buyerSnapshot);

                $series = $db->table('billium_document_series')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('series_ref', $command->seriesRef)
                    ->where('period_key', $command->periodKey)
                    ->first();

                if ($series === null) {
                    throw new ProblemException(ProblemCode::AuthDenied, 'billium.documents.series_not_in_scope');
                }
                if (! RowCast::boolean($series->active, 'active')) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.series_inactive');
                }
                if (RowCast::str($series->document_type, 'document_type') !== DocumentType::Invoice->value) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.series_document_type_mismatch');
                }

                $seriesId = RowCast::integer($series->id, 'id');

                // Step 1 in lock DAG: issue ledger invoice first (locks Obligation 70 -> Invoice 80) and capture locked snapshot.
                $ledgerReceipt = $this->ledgerIssuePolicy->issue(
                    $context,
                    new IssueInvoice($command->commandKey, $command->scope, $command->invoiceRef),
                    $legalDocumentRef,
                );
                $lockedInvoice = $this->lockedInvoiceSnapshots->lockedInvoiceSnapshot($context, $command->scope, $command->invoiceRef);

                // Step 2: verify live locked invoice totals and every refreshed line under Obligation/Invoice lock match finalization.
                self::assertLockedInvoiceMatchesFinalization($lockedInvoice, $ledgerReceipt->body(), $finalization, $legalDocumentRef);

                // Step 3: if another concurrent worker already committed this exact legal basis, replay cleanly without touching series.
                $existingLegal = $db->table('billium_document_legal')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('document_type', DocumentType::Invoice->value)
                    ->where('economic_ref', $command->economicRef)
                    ->where('document_revision', $command->documentRevision)
                    ->first();

                if ($existingLegal !== null) {
                    if (
                        RowCast::str($existingLegal->approved_snapshot_digest, 'approved_snapshot_digest') !== $command->approvedSnapshotDigest
                        || RowCast::str($existingLegal->series_ref, 'series_ref') !== $command->seriesRef
                        || RowCast::str($existingLegal->period_key, 'period_key') !== $command->periodKey
                        || RowCast::str($existingLegal->legal_document_ref, 'legal_document_ref') !== $legalDocumentRef
                    ) {
                        throw new ProblemException(ProblemCode::IdempotencyConflict, 'billium.documents.basis_already_issued');
                    }

                    return new DocumentReceipt(
                        'documents.legal_document.issued',
                        self::viewToReceiptBody(self::hydrate($existingLegal)),
                    );
                }

                // Step 4: lock DocumentSeries (130), increment next_number, insert legal doc + number journal.
                $roundingMode = RowCast::str($finRow->rounding_mode, 'rounding_mode');
                $roundingPolicyVersion = $roundingMode.'/1';
                $termsRoundingPolicy = $finalization->termsSnapshot['roundingPolicyVersion'] ?? null;
                $defaultLineRoundingPolicy = is_string($termsRoundingPolicy) && trim($termsRoundingPolicy) !== ''
                    ? $termsRoundingPolicy
                    : $roundingPolicyVersion;

                $linesSnapshot = self::serializeFinalizationLines($finalization, $defaultLineRoundingPolicy);
                $taxSnapshotPayload = $finalization->taxSnapshot !== null
                    ? [
                        ...DatabaseDocumentFinalizationService::serializeSnapshot($finalization->taxSnapshot),
                        'roundingPolicyVersion' => $roundingPolicyVersion,
                    ]
                    : throw new LogicException('Snapshot-ready finalization missing tax snapshot payload');

                $view = $this->numberingParticipant->allocateAndRecord($context, new AllocateLegalNumberCommand(
                    legalDocumentRef: $legalDocumentRef,
                    documentType: DocumentType::Invoice,
                    economicRef: $command->economicRef,
                    documentRevision: $command->documentRevision,
                    seriesId: $seriesId,
                    profileId: $profile->id,
                    profileVersion: $profile->version,
                    finalizationId: $finalization->id,
                    basisRevision: $finalization->basisRevision,
                    basisDigest: $finalization->basisDigest,
                    approvedSnapshotDigest: $command->approvedSnapshotDigest,
                    currency: $finalization->currency,
                    subtotalMinor: $finalization->subtotalMinor ?? throw new LogicException('Missing subtotalMinor'),
                    taxMinor: $finalization->taxMinor ?? throw new LogicException('Missing taxMinor'),
                    totalMinor: $finalization->totalMinor ?? throw new LogicException('Missing totalMinor'),
                    sellerSnapshot: $profile->sellerSnapshot,
                    buyerSnapshot: $finalization->buyerSnapshot,
                    taxSnapshot: $taxSnapshotPayload,
                    termsSnapshot: $finalization->termsSnapshot,
                    linesSnapshot: $linesSnapshot,
                    displayMetadata: $command->displayMetadata,
                    receiptRef: 'legal-issue:'.$legalDocumentRef,
                ));

                return new DocumentReceipt('documents.legal_document.issued', self::viewToReceiptBody($view));
            },
        );
    }

    public function voidLegalDocument(VoidLegalDocument $command): StoredOutcome
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->legalDocumentRef, 'legalDocumentRef');
        RowCast::bounded($command->reason, 'reason', 500);

        $digest = CanonicalJson::digest([
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'legalDocumentRef' => $command->legalDocumentRef,
            'reason' => $command->reason,
        ]);

        return $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'documents.legal_document.void.v1',
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
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');

                $row = $db->table('billium_document_legal')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('legal_document_ref', $command->legalDocumentRef)
                    ->first();

                if ($row === null) {
                    throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.documents.legal_document_not_found');
                }

                $status = RowCast::str($row->status, 'status');
                if ($status === LegalDocumentStatus::Voided->value) {
                    if (RowCast::optStr($row->void_reason, 'void_reason') === $command->reason) {
                        return new DocumentReceipt('documents.legal_document.voided', self::viewToReceiptBody(self::hydrate($row)));
                    }

                    throw new ProblemException(ProblemCode::InvoiceTransitionInvalid, 'billium.documents.already_voided');
                }

                $affected = $db->table('billium_document_legal')
                    ->where('id', RowCast::integer($row->id, 'id'))
                    ->where('status', LegalDocumentStatus::Issued->value)
                    ->update([
                        'status' => LegalDocumentStatus::Voided->value,
                        'void_reason' => $command->reason,
                        'voided_at' => $now,
                    ]);

                if ($affected === 0) {
                    throw new ProblemException(ProblemCode::InvoiceTransitionInvalid, 'billium.documents.void_transition_conflict');
                }

                $db->table('billium_document_number_journal')->insert([
                    'seller_ref' => $caller->sellerRef,
                    'environment' => $caller->environment,
                    'series_ref' => RowCast::str($row->series_ref, 'series_ref'),
                    'period_key' => RowCast::str($row->period_key, 'period_key'),
                    'sequence_number' => RowCast::integer($row->sequence_number, 'sequence_number'),
                    'legal_number' => RowCast::str($row->legal_number, 'legal_number'),
                    'legal_document_ref' => RowCast::str($row->legal_document_ref, 'legal_document_ref'),
                    'event_kind' => 'voided',
                    'reason' => $command->reason,
                    'occurred_at' => $now,
                ]);

                $updated = $db->table('billium_document_legal')->where('id', RowCast::integer($row->id, 'id'))->first();
                if ($updated === null) {
                    throw new LogicException('Voided legal document disappeared');
                }

                return new DocumentReceipt('documents.legal_document.voided', self::viewToReceiptBody(self::hydrate($updated)));
            },
        );
    }

    public function updateDisplayMetadata(UpdateLegalDocumentDisplayMetadata $command): StoredOutcome
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->legalDocumentRef, 'legalDocumentRef');
        self::assertDisplayMetadataAllowList($command->displayMetadata);

        $digest = CanonicalJson::digest([
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'legalDocumentRef' => $command->legalDocumentRef,
            'displayMetadata' => $command->displayMetadata,
        ]);

        return $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'documents.legal_document.display_metadata.v1',
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

                $row = $db->table('billium_document_legal')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('legal_document_ref', $command->legalDocumentRef)
                    ->first();

                if ($row === null) {
                    throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.documents.legal_document_not_found');
                }

                $merged = array_merge(
                    RowCast::jsonObject($row->display_metadata, 'display_metadata'),
                    $command->displayMetadata,
                );

                $db->table('billium_document_legal')
                    ->where('id', RowCast::integer($row->id, 'id'))
                    ->update(['display_metadata' => CanonicalJson::encodeObject($merged)]);

                $updated = $db->table('billium_document_legal')->where('id', RowCast::integer($row->id, 'id'))->first();
                if ($updated === null) {
                    throw new LogicException('Updated legal document disappeared');
                }

                return new DocumentReceipt('documents.legal_document.display_metadata_updated', self::viewToReceiptBody(self::hydrate($updated)));
            },
        );
    }

    public static function hydrate(stdClass $row): LegalDocumentView
    {
        $rawLines = RowCast::jsonList($row->lines_snapshot, 'lines_snapshot');
        $linesSnapshot = [];
        foreach ($rawLines as $item) {
            if (! is_array($item)) {
                throw new LogicException('Expected JSON object element in lines_snapshot');
            }
            /** @var array<string, mixed> $typed */
            $typed = $item;
            $linesSnapshot[] = $typed;
        }

        return new LegalDocumentView(
            id: RowCast::integer($row->id, 'id'),
            legalDocumentRef: RowCast::str($row->legal_document_ref, 'legal_document_ref'),
            sellerRef: RowCast::str($row->seller_ref, 'seller_ref'),
            environment: RowCast::str($row->environment, 'environment'),
            documentType: DocumentType::from(RowCast::str($row->document_type, 'document_type')),
            economicRef: RowCast::str($row->economic_ref, 'economic_ref'),
            documentRevision: RowCast::integer($row->document_revision, 'document_revision'),
            seriesRef: RowCast::str($row->series_ref, 'series_ref'),
            periodKey: RowCast::str($row->period_key, 'period_key'),
            sequenceNumber: RowCast::integer($row->sequence_number, 'sequence_number'),
            legalNumber: RowCast::str($row->legal_number, 'legal_number'),
            status: LegalDocumentStatus::from(RowCast::str($row->status, 'status')),
            profileVersion: RowCast::integer($row->profile_version, 'profile_version'),
            finalizationId: RowCast::integer($row->finalization_id, 'finalization_id'),
            basisRevision: RowCast::integer($row->basis_revision, 'basis_revision'),
            basisDigest: RowCast::str($row->basis_digest, 'basis_digest'),
            approvedSnapshotDigest: RowCast::str($row->approved_snapshot_digest, 'approved_snapshot_digest'),
            currency: RowCast::str($row->currency, 'currency'),
            subtotalMinor: RowCast::integer($row->subtotal_minor, 'subtotal_minor'),
            taxMinor: RowCast::integer($row->tax_minor, 'tax_minor'),
            totalMinor: RowCast::integer($row->total_minor, 'total_minor'),
            sellerSnapshot: RowCast::jsonObject($row->seller_snapshot, 'seller_snapshot'),
            buyerSnapshot: RowCast::jsonObject($row->buyer_snapshot, 'buyer_snapshot'),
            taxSnapshot: RowCast::jsonObject($row->tax_snapshot, 'tax_snapshot'),
            termsSnapshot: RowCast::jsonObject($row->terms_snapshot, 'terms_snapshot'),
            linesSnapshot: $linesSnapshot,
            displayMetadata: RowCast::jsonObject($row->display_metadata, 'display_metadata'),
            originalLegalDocumentRef: RowCast::optStr($row->original_legal_document_ref, 'original_legal_document_ref'),
            voidReason: RowCast::optStr($row->void_reason, 'void_reason'),
            voidedAt: RowCast::optTimestamp($row->voided_at, 'voided_at'),
            receiptRef: RowCast::str($row->receipt_ref, 'receipt_ref'),
            issuedAt: RowCast::timestamp($row->issued_at, 'issued_at'),
        );
    }

    /** @return array<string, mixed> */
    private static function viewToReceiptBody(LegalDocumentView $view): array
    {
        return [
            'id' => $view->id,
            'legalDocumentRef' => $view->legalDocumentRef,
            'documentType' => $view->documentType->value,
            'economicRef' => $view->economicRef,
            'documentRevision' => $view->documentRevision,
            'seriesRef' => $view->seriesRef,
            'periodKey' => $view->periodKey,
            'sequenceNumber' => $view->sequenceNumber,
            'legalNumber' => $view->legalNumber,
            'status' => $view->status->value,
            'approvedSnapshotDigest' => $view->approvedSnapshotDigest,
            'currency' => $view->currency,
            'subtotalMinor' => $view->subtotalMinor,
            'taxMinor' => $view->taxMinor,
            'totalMinor' => $view->totalMinor,
            'voidReason' => $view->voidReason,
        ];
    }

    private static function deterministicLegalDocumentRef(
        string $sellerRef,
        string $environment,
        DocumentType $type,
        string $economicRef,
        int $revision,
    ): string {
        return 'ldoc-'.substr(hash('sha256', CanonicalJson::encode([
            $sellerRef,
            $environment,
            $type->value,
            $economicRef,
            $revision,
        ])), 0, 24);
    }

    /** @param array<string, mixed> $buyerSnapshot */
    private static function assertRequiredBuyerFields(DocumentProfile $profile, array $buyerSnapshot): void
    {
        foreach ($profile->requiredFields as $field) {
            if (! str_starts_with($field, 'buyer.')) {
                continue;
            }
            $key = substr($field, 6);
            $val = $buyerSnapshot[$key] ?? null;
            if (! is_string($val) || trim($val) === '') {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.missing_required_buyer_field: '.$key);
            }
        }
    }

    /** @param array<string, mixed> $metadata */
    private static function assertDisplayMetadataAllowList(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            if (! in_array($key, self::ALLOWED_DISPLAY_METADATA_KEYS, true)) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.display_metadata_key_forbidden: '.$key);
            }
            if (! is_string($value) && ! is_int($value) && ! is_bool($value)) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.display_metadata_scalar_required');
            }
        }
    }

    /**
     * @param  array{currency: string, subtotalMinor: int, taxMinor: int, totalMinor: int, lines: list<array{obligationRef: string, subtotalMinor: int, taxMinor: int, totalMinor: int}>}  $lockedInvoice
     * @param  array<string, mixed>  $lockedReceiptBody
     */
    private static function assertLockedInvoiceMatchesFinalization(
        array $lockedInvoice,
        array $lockedReceiptBody,
        DocumentFinalizationView $finalization,
        string $legalDocumentRef,
    ): void {
        if (
            ($lockedReceiptBody['legal_document_ref'] ?? null) !== $legalDocumentRef
            || ($lockedReceiptBody['currency'] ?? null) !== $finalization->currency
            || $lockedInvoice['currency'] !== $finalization->currency
        ) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.basis_changed_under_lock');
        }

        $lockedTotalMinor = RowCast::integer($lockedReceiptBody['total_minor'] ?? null, 'total_minor');
        if (
            $finalization->subtotalMinor === null
            || $finalization->taxMinor === null
            || $finalization->totalMinor === null
            || $lockedTotalMinor !== $finalization->totalMinor
            || $lockedInvoice['subtotalMinor'] !== $finalization->subtotalMinor
            || $lockedInvoice['taxMinor'] !== $finalization->taxMinor
            || $lockedInvoice['totalMinor'] !== $finalization->totalMinor
        ) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.basis_changed_under_lock');
        }

        if (count($lockedInvoice['lines']) !== count($finalization->lines)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.basis_changed_under_lock');
        }

        $ledgerByObligation = [];
        foreach ($lockedInvoice['lines'] as $invLine) {
            $obRef = $invLine['obligationRef'];
            if (isset($ledgerByObligation[$obRef])) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.basis_changed_under_lock');
            }
            $ledgerByObligation[$obRef] = [
                'sub' => $invLine['subtotalMinor'],
                'tax' => $invLine['taxMinor'],
                'tot' => $invLine['totalMinor'],
            ];
        }

        $taxByLineRef = [];
        if ($finalization->taxSnapshot !== null) {
            foreach ($finalization->taxSnapshot->lines as $tLine) {
                $taxByLineRef[$tLine->lineRef] = $tLine;
            }
        }
        if (count($taxByLineRef) !== count($finalization->lines)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.basis_changed_under_lock');
        }

        foreach ($finalization->lines as $basisLine) {
            if (! isset($ledgerByObligation[$basisLine->obligationRef], $taxByLineRef[$basisLine->lineRef])) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.basis_changed_under_lock');
            }
            $lLine = $ledgerByObligation[$basisLine->obligationRef];
            $tLine = $taxByLineRef[$basisLine->lineRef];

            if (
                $lLine['sub'] !== $tLine->netMinor
                || $lLine['tax'] !== $tLine->taxMinor
                || $lLine['tot'] !== $tLine->grossMinor
            ) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.basis_changed_under_lock');
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private static function serializeFinalizationLines(
        DocumentFinalizationView $finalization,
        string $defaultRoundingPolicyVersion,
    ): array {
        $taxByLine = [];
        if ($finalization->taxSnapshot !== null) {
            foreach ($finalization->taxSnapshot->lines as $line) {
                $taxByLine[$line->lineRef] = $line;
            }
        }

        $linePolicies = is_array($finalization->termsSnapshot['lineRoundingPolicies'] ?? null)
            ? $finalization->termsSnapshot['lineRoundingPolicies']
            : [];

        $out = [];
        foreach ($finalization->lines as $line) {
            $t = $taxByLine[$line->lineRef] ?? null;
            $lineSpecific = $linePolicies[$line->lineRef] ?? null;
            $lineRounding = is_string($lineSpecific) && trim($lineSpecific) !== ''
                ? $lineSpecific
                : $defaultRoundingPolicyVersion;

            $out[] = [
                'lineRef' => $line->lineRef,
                'obligationRef' => $line->obligationRef,
                'componentRef' => $line->componentRef,
                'quantity' => $line->quantity,
                'unitPriceDecimal' => $line->unitPriceDecimal,
                'netMinor' => $t->netMinor ?? $line->netMinor,
                'discountMinor' => $line->discountMinor,
                'taxCode' => $line->taxCode,
                'taxRate' => $t->rateDecimal ?? $line->taxRate,
                'taxMinor' => $t->taxMinor ?? 0,
                'grossMinor' => $t->grossMinor ?? $line->netMinor,
                'roundingPolicyVersion' => $lineRounding,
                'description' => $line->description,
            ];
        }

        return $out;
    }
}
