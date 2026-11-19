<?php

declare(strict_types=1);

namespace Billium\Documents\Contracts;

use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Documents\Data\DocumentCorrectionView;
use Billium\Documents\Data\DocumentCreditNoteLineView;
use Billium\Documents\Data\DocumentScope;
use Billium\Documents\Data\IssueDocumentCreditNote;
use Billium\Documents\Data\IssuePositiveDocumentCorrection;
use Billium\Documents\Data\RecordDocumentWriteOff;
use Billium\Documents\Data\VoidAndReissueLegalDocument;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
interface DocumentCorrectionService
{
    public function issueCreditNote(IssueDocumentCreditNote $command): StoredOutcome;

    public function issuePositiveCorrection(IssuePositiveDocumentCorrection $command): StoredOutcome;

    public function voidAndReissue(VoidAndReissueLegalDocument $command): StoredOutcome;

    public function recordWriteOff(RecordDocumentWriteOff $command): StoredOutcome;

    public function findCorrection(DocumentScope $scope, string $correctionRef): ?DocumentCorrectionView;

    /** @return list<DocumentCreditNoteLineView> */
    public function creditNoteLines(DocumentScope $scope, string $correctionRef): array;
}
