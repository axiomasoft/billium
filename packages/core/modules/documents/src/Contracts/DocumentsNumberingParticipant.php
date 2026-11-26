<?php

declare(strict_types=1);

namespace Billium\Documents\Contracts;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Documents\Data\AllocateLegalNumberCommand;
use Billium\Documents\Data\LegalDocumentView;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Typed documents numbering participant invoked under the documents-root OperationRunner after invoice locking.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface DocumentsNumberingParticipant
{
    public function allocateAndRecord(TransactionContext $context, AllocateLegalNumberCommand $command): LegalDocumentView;
}
