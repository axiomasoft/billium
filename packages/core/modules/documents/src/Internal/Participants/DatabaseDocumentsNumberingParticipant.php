<?php

declare(strict_types=1);

namespace Billium\Documents\Internal\Participants;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runner\LockScope;
use Billium\Documents\Contracts\DocumentsNumberingParticipant;
use Billium\Documents\Data\AllocateLegalNumberCommand;
use Billium\Documents\Data\LegalDocumentView;
use Billium\Documents\Enums\DocumentType;
use Billium\Documents\Enums\LegalDocumentStatus;
use Billium\Documents\Internal\Canonical\CanonicalJson;
use Billium\Documents\Internal\Support\RowCast;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseDocumentsNumberingParticipant implements DocumentsNumberingParticipant
{
    public function __construct(
        private OperationRunner $runner,
        private CallerContext $callers,
        private DatabaseManager $databases,
    ) {}

    public function allocateAndRecord(TransactionContext $context, AllocateLegalNumberCommand $command): LegalDocumentView
    {
        $this->runner->assertCurrent($context, 'documents');
        $caller = $this->callers->current();
        $db = $this->databases->connection($context->connectionName());

        // Acquire DocumentSeries rank strictly after Invoice (80) per LockRank DAG.
        $context->lock(new LockRequest(
            rank: LockRank::DocumentSeries,
            table: 'billium_document_series',
            keys: [$command->seriesId],
            scope: LockScope::Seller,
        ));

        $series = $db->table('billium_document_series')
            ->where('id', $command->seriesId)
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->first();

        if ($series === null) {
            throw new ProblemException(ProblemCode::AuthDenied, 'billium.documents.series_not_in_scope');
        }
        if (! RowCast::boolean($series->active, 'active')) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.series_inactive');
        }
        if (RowCast::str($series->document_type, 'document_type') !== $command->documentType->value) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.series_document_type_mismatch');
        }

        $sequenceNumber = RowCast::integer($series->next_number, 'next_number');
        $prefix = RowCast::str($series->prefix, 'prefix');
        $periodKey = RowCast::str($series->period_key, 'period_key');
        $padding = RowCast::integer($series->padding, 'padding');
        $seriesRef = RowCast::str($series->series_ref, 'series_ref');

        $legalNumber = self::formatLegalNumber($prefix, $periodKey, $sequenceNumber, $padding);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');

        $db->table('billium_document_series')
            ->where('id', $command->seriesId)
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->update([
                'next_number' => $sequenceNumber + 1,
                'updated_at' => $now,
            ]);

        $legalId = $db->table('billium_document_legal')->insertGetId([
            'legal_document_ref' => $command->legalDocumentRef,
            'seller_ref' => $caller->sellerRef,
            'environment' => $caller->environment,
            'document_type' => $command->documentType->value,
            'economic_ref' => $command->economicRef,
            'document_revision' => $command->documentRevision,
            'series_ref' => $seriesRef,
            'period_key' => $periodKey,
            'sequence_number' => $sequenceNumber,
            'legal_number' => $legalNumber,
            'status' => LegalDocumentStatus::Issued->value,
            'profile_id' => $command->profileId,
            'profile_version' => $command->profileVersion,
            'finalization_id' => $command->finalizationId,
            'basis_revision' => $command->basisRevision,
            'basis_digest' => $command->basisDigest,
            'approved_snapshot_digest' => $command->approvedSnapshotDigest,
            'currency' => $command->currency,
            'subtotal_minor' => $command->subtotalMinor,
            'tax_minor' => $command->taxMinor,
            'total_minor' => $command->totalMinor,
            'seller_snapshot' => CanonicalJson::encodeObject($command->sellerSnapshot),
            'buyer_snapshot' => CanonicalJson::encodeObject($command->buyerSnapshot),
            'tax_snapshot' => CanonicalJson::encodeObject($command->taxSnapshot),
            'terms_snapshot' => CanonicalJson::encodeObject($command->termsSnapshot),
            'lines_snapshot' => CanonicalJson::encode($command->linesSnapshot),
            'display_metadata' => CanonicalJson::encodeObject($command->displayMetadata),
            'original_legal_document_ref' => $command->originalLegalDocumentRef,
            'void_reason' => null,
            'voided_at' => null,
            'receipt_ref' => $command->receiptRef,
            'issued_at' => $now,
        ]);

        $db->table('billium_document_number_journal')->insert([
            'seller_ref' => $caller->sellerRef,
            'environment' => $caller->environment,
            'series_ref' => $seriesRef,
            'period_key' => $periodKey,
            'sequence_number' => $sequenceNumber,
            'legal_number' => $legalNumber,
            'legal_document_ref' => $command->legalDocumentRef,
            'event_kind' => 'allocated',
            'reason' => null,
            'occurred_at' => $now,
        ]);

        return new LegalDocumentView(
            id: $legalId,
            legalDocumentRef: $command->legalDocumentRef,
            sellerRef: $caller->sellerRef,
            environment: $caller->environment,
            documentType: DocumentType::from($command->documentType->value),
            economicRef: $command->economicRef,
            documentRevision: $command->documentRevision,
            seriesRef: $seriesRef,
            periodKey: $periodKey,
            sequenceNumber: $sequenceNumber,
            legalNumber: $legalNumber,
            status: LegalDocumentStatus::Issued,
            profileVersion: $command->profileVersion,
            finalizationId: $command->finalizationId,
            basisRevision: $command->basisRevision,
            basisDigest: $command->basisDigest,
            approvedSnapshotDigest: $command->approvedSnapshotDigest,
            currency: $command->currency,
            subtotalMinor: $command->subtotalMinor,
            taxMinor: $command->taxMinor,
            totalMinor: $command->totalMinor,
            sellerSnapshot: $command->sellerSnapshot,
            buyerSnapshot: $command->buyerSnapshot,
            taxSnapshot: $command->taxSnapshot,
            termsSnapshot: $command->termsSnapshot,
            linesSnapshot: $command->linesSnapshot,
            displayMetadata: $command->displayMetadata,
            originalLegalDocumentRef: $command->originalLegalDocumentRef,
            voidReason: null,
            voidedAt: null,
            receiptRef: $command->receiptRef,
            issuedAt: $now,
        );
    }

    public static function formatLegalNumber(string $prefix, string $periodKey, int $sequenceNumber, int $padding): string
    {
        $padded = str_pad((string) $sequenceNumber, $padding, '0', STR_PAD_LEFT);
        if (strtoupper($periodKey) === 'NONE' || $periodKey === '') {
            return $prefix.'-'.$padded;
        }

        return $prefix.'-'.$periodKey.'-'.$padded;
    }
}
