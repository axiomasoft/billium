<?php

declare(strict_types=1);

namespace Billium\Testkit\Documents;

use Billium\Documents\Contracts\DocumentRenderer;
use Billium\Documents\Data\RenderDocumentInput;
use Billium\Documents\Data\RenderedArtifact;
use Packman\Attributes\Api;
use RuntimeException;

/**
 * Deterministic in-memory DocumentRenderer test double for P6 conformance and integration tests.
 */
#[Api(since: '1.0')]
final class InMemoryDocumentRenderer implements DocumentRenderer
{
    public int $renderCalls = 0;

    public ?string $failNextMessage = null;

    /** @var list<string> */
    public array $overrideArtifactAssetRefs = [];

    /** @var array<string, mixed> */
    public array $extraDiagnostics = [];

    public function __construct(
        private string $name = 'in-memory-pdf',
        private string $version = '1.0.0',
    ) {}

    public function setVersion(string $version): void
    {
        $this->version = $version;
    }

    public function rendererName(): string
    {
        return $this->name;
    }

    public function rendererVersion(): string
    {
        return $this->version;
    }

    public function render(RenderDocumentInput $input): RenderedArtifact
    {
        $this->renderCalls++;
        if ($this->failNextMessage !== null) {
            $msg = $this->failNextMessage;
            $this->failNextMessage = null;

            throw new RuntimeException($msg);
        }

        $body = json_encode([
            'legalDocumentRef' => $input->legalDocumentRef,
            'documentRevision' => $input->documentRevision,
            'legalNumber' => $input->legalNumber,
            'approvedSnapshotDigest' => $input->approvedSnapshotDigest,
            'templateId' => $input->templateId,
            'templateVersion' => $input->templateVersion,
            'locale' => $input->locale,
            'currency' => $input->currency,
            'totalMinor' => $input->totalMinor,
        ], JSON_THROW_ON_ERROR);

        $assetRefs = $this->overrideArtifactAssetRefs !== [] ? $this->overrideArtifactAssetRefs : $input->assetRefs;
        $this->overrideArtifactAssetRefs = [];
        $diag = ['renderer' => $this->name, 'version' => $this->version, ...$this->extraDiagnostics];
        $this->extraDiagnostics = [];

        return new RenderedArtifact(
            contentType: 'application/pdf',
            bytes: '%PDF-1.4 '.$body,
            outputDigest: 'sha256:'.hash('sha256', '%PDF-1.4 '.$body),
            assetRefs: $assetRefs,
            diagnostics: $diag,
        );
    }
}
