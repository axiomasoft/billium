<?php

declare(strict_types=1);

namespace Billium\Documents\Laravel;

use Billium\Documents\Contracts\DocumentCorrectionService;
use Billium\Documents\Contracts\DocumentFinalizationService;
use Billium\Documents\Contracts\DocumentProfileRegistry;
use Billium\Documents\Contracts\DocumentRenderService;
use Billium\Documents\Contracts\DocumentSeriesRegistry;
use Billium\Documents\Contracts\DocumentsNumberingParticipant;
use Billium\Documents\Contracts\DocumentTaxArithmetic;
use Billium\Documents\Contracts\DocumentTaxSnapshotPort;
use Billium\Documents\Contracts\LedgerIssuePolicy;
use Billium\Documents\Contracts\LegalDocumentIssuer;
use Billium\Documents\Contracts\LegalDocumentQueries;
use Billium\Documents\Internal\Participants\DatabaseDocumentsNumberingParticipant;
use Billium\Documents\Internal\Participants\ParticipantLedgerIssuePolicy;
use Billium\Documents\Internal\Queries\DatabaseLegalDocumentQueries;
use Billium\Documents\Internal\Services\DatabaseDocumentCorrectionService;
use Billium\Documents\Internal\Services\DatabaseDocumentFinalizationService;
use Billium\Documents\Internal\Services\DatabaseDocumentProfileRegistry;
use Billium\Documents\Internal\Services\DatabaseDocumentRenderService;
use Billium\Documents\Internal\Services\DatabaseDocumentSeriesRegistry;
use Billium\Documents\Internal\Services\DatabaseLegalDocumentIssuer;
use Billium\Documents\Internal\Tax\BcDocumentTaxArithmetic;
use Billium\Documents\Internal\UnboundDocumentTaxSnapshotPort;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Capability declarations provided by the Billium Documents module.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class DocumentsCapabilities
{
    /** @return array<class-string, class-string> */
    public static function map(): array
    {
        return [
            DocumentTaxArithmetic::class => BcDocumentTaxArithmetic::class,
            DocumentTaxSnapshotPort::class => UnboundDocumentTaxSnapshotPort::class,
            DocumentProfileRegistry::class => DatabaseDocumentProfileRegistry::class,
            DocumentFinalizationService::class => DatabaseDocumentFinalizationService::class,
            DocumentSeriesRegistry::class => DatabaseDocumentSeriesRegistry::class,
            LedgerIssuePolicy::class => ParticipantLedgerIssuePolicy::class,
            DocumentsNumberingParticipant::class => DatabaseDocumentsNumberingParticipant::class,
            LegalDocumentIssuer::class => DatabaseLegalDocumentIssuer::class,
            LegalDocumentQueries::class => DatabaseLegalDocumentQueries::class,
            DocumentRenderService::class => DatabaseDocumentRenderService::class,
            DocumentCorrectionService::class => DatabaseDocumentCorrectionService::class,
        ];
    }

    /** @return class-string */
    public static function unboundTaxSnapshotPort(): string
    {
        return UnboundDocumentTaxSnapshotPort::class;
    }
}
