<?php

declare(strict_types=1);

namespace Billium\Documents\Internal\Queries;

use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Documents\Contracts\LegalDocumentQueries;
use Billium\Documents\Data\DocumentNumberJournalEntry;
use Billium\Documents\Data\DocumentScope;
use Billium\Documents\Data\LegalDocumentView;
use Billium\Documents\Enums\DocumentType;
use Billium\Documents\Internal\Services\DatabaseLegalDocumentIssuer;
use Billium\Documents\Internal\Support\RowCast;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseLegalDocumentQueries implements LegalDocumentQueries
{
    public function __construct(
        private DatabaseManager $databases,
        private CallerContext $callers,
        private BilliumOptions $options,
    ) {}

    public function find(DocumentScope $scope, string $legalDocumentRef): ?LegalDocumentView
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);

        $row = $this->databases->connection($this->options->connection)->table('billium_document_legal')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('legal_document_ref', $legalDocumentRef)
            ->first();

        return $row === null ? null : DatabaseLegalDocumentIssuer::hydrate($row);
    }

    public function findByEconomicRef(DocumentScope $scope, DocumentType $documentType, string $economicRef, int $documentRevision = 1): ?LegalDocumentView
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);

        $row = $this->databases->connection($this->options->connection)->table('billium_document_legal')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('document_type', $documentType->value)
            ->where('economic_ref', $economicRef)
            ->where('document_revision', $documentRevision)
            ->first();

        return $row === null ? null : DatabaseLegalDocumentIssuer::hydrate($row);
    }

    /** @return list<DocumentNumberJournalEntry> */
    public function numberJournal(DocumentScope $scope, string $seriesRef, string $periodKey): array
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);

        $rows = $this->databases->connection($this->options->connection)->table('billium_document_number_journal')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('series_ref', $seriesRef)
            ->where('period_key', $periodKey)
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = new DocumentNumberJournalEntry(
                id: RowCast::integer($row->id, 'id'),
                seriesRef: RowCast::str($row->series_ref, 'series_ref'),
                periodKey: RowCast::str($row->period_key, 'period_key'),
                sequenceNumber: RowCast::integer($row->sequence_number, 'sequence_number'),
                legalNumber: RowCast::str($row->legal_number, 'legal_number'),
                legalDocumentRef: RowCast::str($row->legal_document_ref, 'legal_document_ref'),
                eventKind: RowCast::str($row->event_kind, 'event_kind'),
                reason: RowCast::optStr($row->reason, 'reason'),
                occurredAt: RowCast::timestamp($row->occurred_at, 'occurred_at'),
            );
        }

        return $out;
    }
}
