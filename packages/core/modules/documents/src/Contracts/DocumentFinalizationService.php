<?php

declare(strict_types=1);

namespace Billium\Documents\Contracts;

use Billium\Documents\Data\AttachSnapshotResult;
use Billium\Documents\Data\AttachTaxSnapshot;
use Billium\Documents\Data\CreateDocumentDraft;
use Billium\Documents\Data\DocumentFinalizationEventView;
use Billium\Documents\Data\DocumentFinalizationView;
use Billium\Documents\Data\DocumentScope;
use Billium\Documents\Data\ReviseDocumentDraft;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
interface DocumentFinalizationService
{
    public function createDraft(CreateDocumentDraft $command): DocumentFinalizationView;

    public function startFinalization(DocumentScope $scope, string $finalizationRef): DocumentFinalizationView;

    public function reviseDraft(ReviseDocumentDraft $command): DocumentFinalizationView;

    public function finalizeWithTaxPort(DocumentScope $scope, string $finalizationRef): AttachSnapshotResult;

    public function attachTaxSnapshot(AttachTaxSnapshot $command): AttachSnapshotResult;

    public function find(DocumentScope $scope, string $finalizationRef, ?int $finalizationRevision = null): ?DocumentFinalizationView;

    /** @return list<DocumentFinalizationEventView> */
    public function events(DocumentScope $scope, string $finalizationRef): array;
}
