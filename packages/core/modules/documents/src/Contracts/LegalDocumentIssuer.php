<?php

declare(strict_types=1);

namespace Billium\Documents\Contracts;

use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Documents\Data\IssueLegalDocument;
use Billium\Documents\Data\UpdateLegalDocumentDisplayMetadata;
use Billium\Documents\Data\VoidLegalDocument;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
interface LegalDocumentIssuer
{
    public function issueInvoiceDocument(IssueLegalDocument $command): StoredOutcome;

    public function voidLegalDocument(VoidLegalDocument $command): StoredOutcome;

    public function updateDisplayMetadata(UpdateLegalDocumentDisplayMetadata $command): StoredOutcome;
}
