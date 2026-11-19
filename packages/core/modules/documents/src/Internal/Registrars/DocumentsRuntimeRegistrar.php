<?php

declare(strict_types=1);

namespace Billium\Documents\Internal\Registrars;

use Billium\Documents\Contracts\DocumentFinalizationService;
use Billium\Documents\Contracts\DocumentProfileRegistry;
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
use Billium\Documents\Internal\Services\DatabaseDocumentFinalizationService;
use Billium\Documents\Internal\Services\DatabaseDocumentProfileRegistry;
use Billium\Documents\Internal\Services\DatabaseDocumentSeriesRegistry;
use Billium\Documents\Internal\Services\DatabaseLegalDocumentIssuer;
use Billium\Documents\Internal\Tax\BcDocumentTaxArithmetic;
use Billium\Documents\Internal\UnboundDocumentTaxSnapshotPort;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Internal;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Internal]
final class DocumentsRuntimeRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->bind(DocumentTaxArithmetic::class, BcDocumentTaxArithmetic::class);
        $app->bindIf(DocumentTaxSnapshotPort::class, UnboundDocumentTaxSnapshotPort::class);
        $app->bind(DocumentProfileRegistry::class, DatabaseDocumentProfileRegistry::class);
        $app->bind(DocumentFinalizationService::class, DatabaseDocumentFinalizationService::class);
        $app->bind(DocumentSeriesRegistry::class, DatabaseDocumentSeriesRegistry::class);
        $app->bind(LedgerIssuePolicy::class, ParticipantLedgerIssuePolicy::class);
        $app->bind(DocumentsNumberingParticipant::class, DatabaseDocumentsNumberingParticipant::class);
        $app->bind(LegalDocumentIssuer::class, DatabaseLegalDocumentIssuer::class);
        $app->bind(LegalDocumentQueries::class, DatabaseLegalDocumentQueries::class);
    }

    #[Override]
    public function boot(Application $app): void {}
}
