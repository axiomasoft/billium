<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P6;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\P6\Support\DocumentsTestCase;
use Billium\Documents\Contracts\DocumentRenderer;
use Billium\Documents\Contracts\DocumentStorage;
use Billium\Documents\Data\AttachRenderResult;
use Billium\Documents\Data\ReadDocumentRender;
use Billium\Documents\Data\RequestDocumentRender;
use Billium\Documents\Data\StoreRenderBlob;
use Billium\Documents\Enums\RenderState;
use Billium\Testkit\Documents\InMemoryDocumentRenderer;
use Billium\Testkit\Documents\InMemoryDocumentStorage;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class DocumentRenderTest extends DocumentsTestCase
{
    public function test_render_request_idempotent_storage_timeout_retry_and_no_second_legal_document(): void
    {
        $this->registerCompleteProfile();
        $this->registerSeries();
        $fin = $this->prepareFinalizedInvoiceBasis(suffix: 'rnd1');
        $issued = $this->issuer()->issueInvoiceDocument($this->issueCommand('cmd-rnd1', 'rnd1', $fin->approvedSnapshotDigest ?? ''));
        $legalRef = (string) ($issued->body['legalDocumentRef'] ?? '');

        $renderer = new InMemoryDocumentRenderer('in-memory-pdf', '1.0.0');
        $storage = new InMemoryDocumentStorage;
        $this->application()->instance(DocumentRenderer::class, $renderer);
        $this->application()->instance(DocumentStorage::class, $storage);

        $reqCmd = new RequestDocumentRender(
            scope: $this->docScope(),
            renderRequestRef: 'rreq-1',
            legalDocumentRef: $legalRef,
            templateId: 'invoice-default',
            templateVersion: '1.0',
            templateDigest: self::digest('t'),
            locale: 'en-US',
            rendererName: 'in-memory-pdf',
            rendererVersion: '1.0.0',
            rendererDigest: self::digest('r'),
            assetRefs: ['asset://brand/logo.png'],
        );

        $first = $this->renderService()->requestRender($reqCmd);
        $repeat = $this->renderService()->requestRender($reqCmd);
        self::assertSame($first->id, $repeat->id);
        self::assertSame($first->renderKeyDigest, $repeat->renderKeyDigest);
        self::assertSame(RenderState::Pending, $first->state);
        self::assertSame(1, $this->rows('billium_document_legal'));

        // Simulate timeout AFTER storage accepted the upload on attempt 1.
        $storage->failAfterStoreNext = true;
        try {
            $this->renderService()->executeRender($this->docScope(), 'rreq-1');
            self::fail('Storage timeout after upload must throw TransportUnavailable');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::TransportUnavailable, $error->problem);
        }
        self::assertSame(1, $storage->activeBlobCount());

        // Retry returns the identical blobRef by uploadKey and attaches cleanly.
        $retried = $this->renderService()->executeRender($this->docScope(), 'rreq-1');
        self::assertTrue($retried->attached);
        self::assertFalse($retried->staleIgnored);
        self::assertSame(RenderState::Ready, $retried->render->state);
        self::assertNotNull($retried->render->blobRef);
        self::assertSame(1, $storage->activeBlobCount());
        self::assertSame(1, $this->rows('billium_document_render_blob'));
        self::assertSame('attached', $this->value('SELECT status FROM billium_document_render_blob'));
        self::assertSame(1, $this->rows('billium_document_legal'));
        self::assertSame(2, $this->value("SELECT next_number FROM billium_document_series WHERE series_ref='INV'"));
    }

    public function test_stale_render_attach_marks_orphan_without_overwriting_newer_revision_and_cleanup_respects_pin(): void
    {
        $this->registerCompleteProfile();
        $this->registerSeries();
        $fin = $this->prepareFinalizedInvoiceBasis(suffix: 'rnd2');
        $issued = $this->issuer()->issueInvoiceDocument($this->issueCommand('cmd-rnd2', 'rnd2', $fin->approvedSnapshotDigest ?? ''));
        $legalRef = (string) ($issued->body['legalDocumentRef'] ?? '');

        $clock = new DateTimeImmutable('2026-11-19T12:00:00Z', new DateTimeZone('UTC'));
        $renderer = new InMemoryDocumentRenderer('in-memory-pdf', '1.0.0');
        $storage = new InMemoryDocumentStorage($clock);
        $this->application()->instance(DocumentRenderer::class, $renderer);
        $this->application()->instance(DocumentStorage::class, $storage);

        $r1 = $this->renderService()->requestRender(new RequestDocumentRender(
            scope: $this->docScope(),
            renderRequestRef: 'rreq-rev1',
            legalDocumentRef: $legalRef,
            templateId: 'invoice-default',
            templateVersion: '1.0',
            templateDigest: self::digest('1'),
            locale: 'en-US',
            rendererName: 'in-memory-pdf',
            rendererVersion: '1.0.0',
            rendererDigest: self::digest('a'),
        ));
        self::assertSame(1, $r1->renderRevision);

        // Revision 2 (updated template) supersedes incomplete rev 1.
        $r2 = $this->renderService()->requestRender(new RequestDocumentRender(
            scope: $this->docScope(),
            renderRequestRef: 'rreq-rev2',
            legalDocumentRef: $legalRef,
            templateId: 'invoice-default',
            templateVersion: '2.0',
            templateDigest: self::digest('2'),
            locale: 'en-US',
            rendererName: 'in-memory-pdf',
            rendererVersion: '1.0.0',
            rendererDigest: self::digest('a'),
        ));
        self::assertSame(2, $r2->renderRevision);

        // Execute revision 2 so rev 2 is ready and attached.
        $ready2 = $this->renderService()->executeRender($this->docScope(), 'rreq-rev2');
        self::assertTrue($ready2->attached);
        $attachedBlob = $ready2->render->blobRef ?? '';

        // Simulate two orphan uploads stored by storage: one unpinned/expired, one pinned in the future.
        $expiredBlob = $storage->store(new StoreRenderBlob(
            sellerRef: 'seller-a',
            environment: 'test',
            uploadKey: 'stale-orphan-expired',
            contentType: 'application/pdf',
            bytes: '%PDF-1.4 stale-1',
            outputDigest: 'sha256:'.hash('sha256', '%PDF-1.4 stale-1'),
        ));
        $pinnedBlob = $storage->store(new StoreRenderBlob(
            sellerRef: 'seller-a',
            environment: 'test',
            uploadKey: 'stale-orphan-pinned',
            contentType: 'application/pdf',
            bytes: '%PDF-1.4 stale-2',
            outputDigest: 'sha256:'.hash('sha256', '%PDF-1.4 stale-2'),
            retentionPinnedUntil: $clock->modify('+7 days'),
        ));

        $nowStr = $clock->format('Y-m-d\TH:i:s.u\Z');
        $this->owner()->insert(
            'INSERT INTO public.billium_document_render_blob (seller_ref, environment, upload_key, blob_ref, render_request_id, output_digest, content_type, byte_size, status, retention_pinned_until, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'seller-a', 'test', 'stale-orphan-expired', $expiredBlob->blobRef, $r1->id, $expiredBlob->outputDigest, 'application/pdf', $expiredBlob->byteSize, 'uploaded', $clock->modify('-1 hour')->format('Y-m-d\TH:i:s.u\Z'), $nowStr, $nowStr,
                'seller-a', 'test', 'stale-orphan-pinned', $pinnedBlob->blobRef, $r1->id, $pinnedBlob->outputDigest, 'application/pdf', $pinnedBlob->byteSize, 'orphaned', $clock->modify('+7 days')->format('Y-m-d\TH:i:s.u\Z'), $nowStr, $nowStr,
            ],
        );

        // Late attach for superseded rev 1 is safely ignored, leaves rev 2 untouched, and marks expiredBlob as orphaned.
        $late = $this->renderService()->attachRenderResult(new AttachRenderResult(
            scope: $this->docScope(),
            renderRequestRef: 'rreq-rev1',
            renderRevision: 1,
            renderKeyDigest: $r1->renderKeyDigest,
            blobRef: $expiredBlob->blobRef,
            outputDigest: $expiredBlob->outputDigest,
            contentType: 'application/pdf',
            byteSize: $expiredBlob->byteSize,
        ));
        self::assertFalse($late->attached);
        self::assertTrue($late->staleIgnored);
        self::assertSame(2, $late->render->renderRevision);
        self::assertSame($attachedBlob, $late->render->blobRef);
        self::assertSame('orphaned', $this->value('SELECT status FROM billium_document_render_blob WHERE blob_ref=?', [$expiredBlob->blobRef]));

        // Owner orphan cleanup deletes unpinned/expired orphan, skips retention-pinned orphan, never touches attached blob.
        $cleanup = $this->renderService()->cleanupOrphanBlobs($this->docScope(), $clock);
        self::assertSame(1, $cleanup->deletedCount);
        self::assertSame(1, $cleanup->skippedPinnedCount);
        self::assertSame([$expiredBlob->blobRef], $cleanup->deletedBlobRefs);
        self::assertFalse($storage->isBlobAlive($expiredBlob->blobRef));
        self::assertTrue($storage->isBlobAlive($pinnedBlob->blobRef));
        self::assertTrue($storage->isBlobAlive($attachedBlob));
        self::assertSame('attached', $this->value('SELECT status FROM billium_document_render_blob WHERE blob_ref=?', [$attachedBlob]));
    }

    public function test_read_render_auth_ttl_expiry_query_and_disabled_renderer_history_read(): void
    {
        $this->registerCompleteProfile();
        $this->registerSeries();
        $fin = $this->prepareFinalizedInvoiceBasis(suffix: 'rnd3');
        $issued = $this->issuer()->issueInvoiceDocument($this->issueCommand('cmd-rnd3', 'rnd3', $fin->approvedSnapshotDigest ?? ''));
        $legalRef = (string) ($issued->body['legalDocumentRef'] ?? '');

        $clock = new DateTimeImmutable('2026-11-19T12:00:00Z', new DateTimeZone('UTC'));
        $renderer = new InMemoryDocumentRenderer;
        $storage = new InMemoryDocumentStorage($clock);
        $this->application()->instance(DocumentRenderer::class, $renderer);
        $this->application()->instance(DocumentStorage::class, $storage);

        $this->renderService()->requestRender(new RequestDocumentRender(
            scope: $this->docScope(),
            renderRequestRef: 'rreq-read',
            legalDocumentRef: $legalRef,
            templateId: 'default',
            templateVersion: '1.0',
            templateDigest: self::digest('t'),
            locale: 'en-US',
            rendererName: 'in-memory-pdf',
            rendererVersion: '1.0.0',
            rendererDigest: self::digest('r'),
        ));
        $ready = $this->renderService()->executeRender($this->docScope(), 'rreq-read');
        $blobRef = $ready->render->blobRef ?? '';

        // Valid readRender returns short-lived grant that expires after TTL and never persists URL in DB.
        $read = $this->renderService()->readRender(new ReadDocumentRender(
            scope: $this->docScope(),
            legalDocumentRef: $legalRef,
            templateId: 'default',
            locale: 'en-US',
            ttlSeconds: 60,
        ));
        self::assertStringContainsString($blobRef, $read->temporaryUrl);
        self::assertNotNull($storage->fetchByTemporaryUrl($read->temporaryUrl, $clock->modify('+30 seconds')));
        self::assertNull($storage->fetchByTemporaryUrl($read->temporaryUrl, $clock->modify('+61 seconds')));
        self::assertSame(0, $this->rows('billium_document_render_request', "blob_ref LIKE 'https://%'"));

        // billium.query.v1 readRender dispatch works.
        $queryJson = json_encode([
            'schema' => 'billium.query.v1',
            'installationRef' => self::INSTALLATION,
            'environment' => 'test',
            'sellerRef' => 'seller-a',
            'query' => 'readRender',
            'selector' => [
                'documentRef' => $legalRef,
                'templateId' => 'default',
                'locale' => 'en-US',
                'ttlSeconds' => '120',
            ],
        ], JSON_THROW_ON_ERROR);
        $dispatched = $this->renderService()->dispatchReadRenderQuery($queryJson);
        self::assertIsArray($dispatched['result']);
        self::assertSame($blobRef, $dispatched['result']['blobRef'] ?? null);

        // Actor authorization check rejects unauthorized actor.
        try {
            $this->renderService()->readRender(new ReadDocumentRender(
                scope: $this->docScope(),
                legalDocumentRef: $legalRef,
                templateId: 'default',
                locale: 'en-US',
                allowedActorRefs: ['actor-finance-only'],
            ));
            self::fail('Unauthorized actor must be rejected');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::AuthDenied, $error->problem);
        }

        // Cross-seller authorization rejects Seller B even with exact legalDocumentRef knowledge.
        $this->callers->as('seller-b');
        try {
            $this->renderService()->readRender(new ReadDocumentRender(
                scope: $this->docScope('seller-b'),
                legalDocumentRef: $legalRef,
                templateId: 'default',
                locale: 'en-US',
            ));
            self::fail('Cross-seller readRender must be refused');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::AuthDenied, $error->problem);
        }
        $this->callers->as('seller-a');

        // Disabled/unbound renderer refuses new executeRender, while readRender continues serving history.
        $this->application()->offsetUnset(DocumentRenderer::class);
        $this->renderService()->requestRender(new RequestDocumentRender(
            scope: $this->docScope(),
            renderRequestRef: 'rreq-after-disable',
            legalDocumentRef: $legalRef,
            templateId: 'default',
            templateVersion: '1.1',
            templateDigest: self::digest('x'),
            locale: 'en-US',
            rendererName: 'in-memory-pdf',
            rendererVersion: '1.0.0',
            rendererDigest: self::digest('r'),
        ));
        try {
            $this->renderService()->executeRender($this->docScope(), 'rreq-after-disable');
            self::fail('Disabled/unbound renderer must refuse new render execution');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::CapabilityUnsupported, $error->problem);
        }

        $historyRead = $this->renderService()->readRender(new ReadDocumentRender(
            scope: $this->docScope(),
            legalDocumentRef: $legalRef,
            templateId: 'default',
            locale: 'en-US',
        ));
        self::assertSame($blobRef, $historyRead->blobRef);
    }

    public function test_asset_allow_list_and_secret_redaction_in_diagnostics(): void
    {
        $this->registerCompleteProfile();
        $this->registerSeries();
        $fin = $this->prepareFinalizedInvoiceBasis(suffix: 'rnd4');
        $issued = $this->issuer()->issueInvoiceDocument($this->issueCommand('cmd-rnd4', 'rnd4', $fin->approvedSnapshotDigest ?? ''));
        $legalRef = (string) ($issued->body['legalDocumentRef'] ?? '');

        $renderer = new InMemoryDocumentRenderer;
        $storage = new InMemoryDocumentStorage;
        $this->application()->instance(DocumentRenderer::class, $renderer);
        $this->application()->instance(DocumentStorage::class, $storage);

        // Remote https asset rejected and secret token redacted from stored diagnostics.
        $this->renderService()->requestRender(new RequestDocumentRender(
            scope: $this->docScope(),
            renderRequestRef: 'rreq-bad-asset',
            legalDocumentRef: $legalRef,
            templateId: 'default',
            templateVersion: '1.0',
            templateDigest: self::digest('a'),
            locale: 'en-US',
            rendererName: 'in-memory-pdf',
            rendererVersion: '1.0.0',
            rendererDigest: self::digest('r'),
            assetRefs: ['https://evil.example/logo.png?token=topsecret123'],
        ));

        try {
            $this->renderService()->executeRender($this->docScope(), 'rreq-bad-asset');
            self::fail('Disallowed remote asset scheme must fail render');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
            self::assertSame('billium.documents.render_asset_forbidden', $error->detail);
        }

        $rawDiag = (string) $this->value("SELECT redacted_diagnostics::text FROM billium_document_render_request WHERE render_request_ref='rreq-bad-asset'");
        self::assertStringNotContainsString('topsecret123', $rawDiag);
        self::assertStringContainsString('[REDACTED]', $rawDiag);

        // Allow-listed asset succeeds while Bearer tokens & sensitive diagnostic keys are scrubbed.
        $renderer->extraDiagnostics = [
            'authorization' => 'Bearer super.jwt.token',
            'signedLink' => 'asset://templates/a?sig=secret-signature',
        ];
        $this->renderService()->requestRender(new RequestDocumentRender(
            scope: $this->docScope(),
            renderRequestRef: 'rreq-ok-asset',
            legalDocumentRef: $legalRef,
            templateId: 'default',
            templateVersion: '1.0',
            templateDigest: self::digest('a'),
            locale: 'en-US',
            rendererName: 'in-memory-pdf',
            rendererVersion: '1.0.0',
            rendererDigest: self::digest('r'),
            assetRefs: ['asset://brand/logo.png'],
        ));
        $ok = $this->renderService()->executeRender($this->docScope(), 'rreq-ok-asset');
        self::assertTrue($ok->attached);

        $okDiag = (string) $this->value("SELECT redacted_diagnostics::text FROM billium_document_render_request WHERE render_request_ref='rreq-ok-asset'");
        self::assertStringNotContainsString('super.jwt.token', $okDiag);
        self::assertStringNotContainsString('secret-signature', $okDiag);
        self::assertStringContainsString('[REDACTED]', $okDiag);
    }
}
