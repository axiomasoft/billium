<?php

declare(strict_types=1);

namespace Billium\Core\Plugins;

use Billium\Documents\Contracts\DocumentCorrectionService;
use Billium\Documents\Contracts\DocumentDelivery;
use Billium\Documents\Contracts\DocumentFinalizationService;
use Billium\Documents\Contracts\DocumentProfileRegistry;
use Billium\Documents\Contracts\DocumentRenderer;
use Billium\Documents\Contracts\DocumentRenderService;
use Billium\Documents\Contracts\DocumentSeriesRegistry;
use Billium\Documents\Contracts\DocumentsNumberingParticipant;
use Billium\Documents\Contracts\DocumentStorage;
use Billium\Documents\Contracts\DocumentTaxArithmetic;
use Billium\Documents\Contracts\DocumentTaxSnapshotPort;
use Billium\Documents\Contracts\LedgerIssuePolicy;
use Billium\Documents\Contracts\LegalDocumentIssuer;
use Billium\Documents\Contracts\LegalDocumentQueries;
use Billium\Documents\Laravel\DocumentsCapabilities;
use Override;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Composition\ProvidesCapabilities;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * Stateless Packman extension plugin composing Billium Documents schema ownership and capability contracts.
 */
#[Api(since: '1.0')]
final class DocumentsPlugin implements ExtensionPlugin, WithCapabilities, WithSchema
{
    use OwnsSchema;
    use ProvidesCapabilities;

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'axiomasoft.billium.documents',
            version: '0.1.0',
            requires: ['axiomasoft.billium.platform', 'axiomasoft.billium.ledger'],
            optional: [],
            resourceRoot: dirname(__DIR__, 2),
            databases: ['pgsql'],
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void {}

    #[Override]
    public function tablePrefix(): string
    {
        return 'billium_';
    }

    /** @return list<string> */
    #[Override]
    public function tableNouns(): array
    {
        return [
            'document_profile',
            'document_finalization',
            'document_finalization_event',
            'document_series',
            'document_legal',
            'document_number_journal',
            'document_render_request',
            'document_render_blob',
            'document_correction',
            'document_credit_note_line',
        ];
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations/documents';
    }

    /** @return array<class-string, string> */
    #[Override]
    public function definedCapabilities(): array
    {
        return [
            DocumentTaxArithmetic::class => Cardinality::OptionalSingle->value,
            DocumentTaxSnapshotPort::class => Cardinality::OptionalSingle->value,
            DocumentProfileRegistry::class => Cardinality::OptionalSingle->value,
            DocumentFinalizationService::class => Cardinality::OptionalSingle->value,
            DocumentSeriesRegistry::class => Cardinality::OptionalSingle->value,
            LedgerIssuePolicy::class => Cardinality::OptionalSingle->value,
            DocumentsNumberingParticipant::class => Cardinality::OptionalSingle->value,
            LegalDocumentIssuer::class => Cardinality::OptionalSingle->value,
            LegalDocumentQueries::class => Cardinality::OptionalSingle->value,
            DocumentRenderer::class => Cardinality::OptionalSingle->value,
            DocumentStorage::class => Cardinality::OptionalSingle->value,
            DocumentDelivery::class => Cardinality::OptionalSingle->value,
            DocumentRenderService::class => Cardinality::OptionalSingle->value,
            DocumentCorrectionService::class => Cardinality::OptionalSingle->value,
        ];
    }

    /** @return array<class-string, class-string> */
    #[Override]
    public function providedCapabilities(): array
    {
        return DocumentsCapabilities::map();
    }
}
