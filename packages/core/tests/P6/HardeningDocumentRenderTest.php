<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P6;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\P6\Support\DocumentsTestCase;
use Billium\Documents\Contracts\DocumentRenderer;
use Billium\Documents\Contracts\DocumentStorage;
use Billium\Documents\Data\AttachRenderResult;
use Billium\Documents\Data\RequestDocumentRender;
use Billium\Documents\Enums\RenderState;
use Billium\Testkit\Documents\InMemoryDocumentRenderer;
use Billium\Testkit\Documents\InMemoryDocumentStorage;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class HardeningDocumentRenderTest extends DocumentsTestCase
{
    public function test_asset_query_secrets_are_not_stored_or_copied_into_problem_details(): void
    {
        $legalRef = $this->issueLegal('hrd-secret');
        $this->application()->instance(DocumentRenderer::class, new InMemoryDocumentRenderer('in-memory-pdf', '1.0.0'));
        $this->application()->instance(DocumentStorage::class, new InMemoryDocumentStorage);
        $asset = 'https://cdn.example/logo.png?theme=plain&token=topsecret123&key=exactkeysecret&auth=exactauthsecret'
            .'&signature=supersecretvalue&api_key=anothersecretvalue&access_token=thirdsecretvalue&authorization=authsecretvalue';

        $this->renderService()->requestRender(new RequestDocumentRender(
            scope: $this->docScope(),
            renderRequestRef: 'rreq-hrd-secret',
            legalDocumentRef: $legalRef,
            templateId: 'invoice-default',
            templateVersion: '1.0',
            templateDigest: self::digest('t'),
            locale: 'en-US',
            rendererName: 'in-memory-pdf',
            rendererVersion: '1.0.0',
            rendererDigest: self::digest('r'),
            assetRefs: [$asset],
        ));

        $this->assertDiagnosticsHaveNoSecrets('rreq-hrd-secret');

        try {
            $this->renderService()->executeRender($this->docScope(), 'rreq-hrd-secret');
            self::fail('Disallowed remote asset must fail render');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
            self::assertSame('billium.documents.render_asset_forbidden', $error->detail);
            $details = json_encode($error->errorDescriptor()->details, JSON_THROW_ON_ERROR);
            self::assertIsString($details);
            foreach (self::secretFragments() as $secret) {
                self::assertStringNotContainsString($secret, $error->detail);
                self::assertStringNotContainsString($secret, $details);
            }
        }

        $this->assertDiagnosticsHaveNoSecrets('rreq-hrd-secret');
        self::assertSame(RenderState::Failed->value, $this->value("SELECT state FROM billium_document_render_request WHERE render_request_ref='rreq-hrd-secret'"));
        self::assertNull($this->value("SELECT blob_ref FROM billium_document_render_request WHERE render_request_ref='rreq-hrd-secret'"));
    }

    public function test_attach_does_not_complete_a_render_when_the_blob_was_not_stored(): void
    {
        $legalRef = $this->issueLegal('hrd-blob');
        $requested = $this->renderService()->requestRender(new RequestDocumentRender(
            scope: $this->docScope(),
            renderRequestRef: 'rreq-hrd-missing',
            legalDocumentRef: $legalRef,
            templateId: 'invoice-default',
            templateVersion: '1.0',
            templateDigest: self::digest('t'),
            locale: 'en-US',
            rendererName: 'in-memory-pdf',
            rendererVersion: '1.0.0',
            rendererDigest: self::digest('r'),
        ));

        try {
            $this->renderService()->attachRenderResult(new AttachRenderResult(
                scope: $this->docScope(),
                renderRequestRef: 'rreq-hrd-missing',
                renderRevision: $requested->renderRevision,
                renderKeyDigest: $requested->renderKeyDigest,
                blobRef: 'blob-not-stored',
                outputDigest: 'sha256:'.hash('sha256', 'missing'),
                contentType: 'application/pdf',
                byteSize: 7,
            ));
            self::fail('A render must not complete before its blob is stored');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ReferenceUnknown, $error->problem);
            self::assertSame('billium.documents.render_blob_not_stored', $error->detail);
        }

        self::assertSame(RenderState::Pending->value, $this->value("SELECT state FROM billium_document_render_request WHERE render_request_ref='rreq-hrd-missing'"));
        self::assertNull($this->value("SELECT blob_ref FROM billium_document_render_request WHERE render_request_ref='rreq-hrd-missing'"));
        self::assertSame(0, $this->rows('billium_document_render_blob', 'blob_ref = ?', ['blob-not-stored']));
    }

    private function issueLegal(string $suffix): string
    {
        $this->registerCompleteProfile();
        $this->registerSeries();
        $fin = $this->prepareFinalizedInvoiceBasis(suffix: $suffix);
        $issued = $this->issuer()->issueInvoiceDocument($this->issueCommand('cmd-'.$suffix, $suffix, $fin->approvedSnapshotDigest ?? ''));

        return (string) ($issued->body['legalDocumentRef'] ?? '');
    }

    private function assertDiagnosticsHaveNoSecrets(string $renderRequestRef): void
    {
        $diagnostics = (string) $this->value(
            'SELECT redacted_diagnostics::text FROM billium_document_render_request WHERE render_request_ref = ?',
            [$renderRequestRef],
        );
        foreach (self::secretFragments() as $secret) {
            self::assertStringNotContainsString($secret, $diagnostics);
        }
        self::assertStringContainsString('[REDACTED]', $diagnostics);
        self::assertStringContainsString('theme=plain', $diagnostics);
    }

    /** @return list<string> */
    private static function secretFragments(): array
    {
        return [
            'topsecret123',
            'exactkeysecret',
            'exactauthsecret',
            'supersecretvalue',
            'anothersecretvalue',
            'thirdsecretvalue',
            'authsecretvalue',
        ];
    }
}
