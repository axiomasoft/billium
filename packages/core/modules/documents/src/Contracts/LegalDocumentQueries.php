<?php

declare(strict_types=1);

namespace Billium\Documents\Contracts;

use Billium\Documents\Data\DocumentNumberJournalEntry;
use Billium\Documents\Data\DocumentScope;
use Billium\Documents\Data\LegalDocumentView;
use Billium\Documents\Enums\DocumentType;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
interface LegalDocumentQueries
{
    public function find(DocumentScope $scope, string $legalDocumentRef): ?LegalDocumentView;

    public function findByEconomicRef(DocumentScope $scope, DocumentType $documentType, string $economicRef, int $documentRevision = 1): ?LegalDocumentView;

    /** @return list<DocumentNumberJournalEntry> */
    public function numberJournal(DocumentScope $scope, string $seriesRef, string $periodKey): array;
}
