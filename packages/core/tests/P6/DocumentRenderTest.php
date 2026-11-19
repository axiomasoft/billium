<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P6;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\P6\Support\DocumentsTestCase;
use Billium\Documents\Contracts\DocumentRenderer;
use Billium\Documents\Contracts\DocumentStorage;
use Billium\Documents\Data\AttachRenderResult;
use Billium\Documents\Data\ReadBlobGrantRequest;
use Billium\Documents\Data\ReadDocumentRender;
use Billium\Documents\Data\RequestDocumentRender;
use Billium\Documents\Data\StoredBlob;
use Billium\Documents\Data\StoreRenderBlob;
use Billium\Documents\Data\TemporaryBlobGrant;
use Billium\Documents\Enums\RenderState;
use Billium\Testkit\Documents\InMemoryDocumentRenderer;
use Billium\Testkit\Documents\InMemoryDocumentStorage;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

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

        // dispatchReadRenderQuery enforces host-supplied allowedActorRefs boundary.
        $dispatchedAuthorized = $this->renderService()->dispatchReadRenderQuery($queryJson, [$this->callers->current()->actorRef]);
        self::assertIsArray($dispatchedAuthorized['result']);
        self::assertSame($blobRef, $dispatchedAuthorized['result']['blobRef'] ?? null);

        try {
            $this->renderService()->dispatchReadRenderQuery($queryJson, ['actor-finance-only']);
            self::fail('Unauthorized actor must be rejected by dispatchReadRenderQuery');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::AuthDenied, $error->problem);
            self::assertSame('billium.documents.actor_not_authorized', $error->detail);
        }

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

    public function test_orphan_cleanup_race_with_concurrent_retry_attach_never_deletes_attached_blob_and_refuses_deleted_blob(): void
    {
        $this->registerCompleteProfile();
        $this->registerSeries();
        $fin = $this->prepareFinalizedInvoiceBasis(suffix: 'rnd-race');
        $issued = $this->issuer()->issueInvoiceDocument($this->issueCommand('cmd-rnd-race', 'rnd-race', $fin->approvedSnapshotDigest ?? ''));
        $legalRef = (string) ($issued->body['legalDocumentRef'] ?? '');

        $clock = new DateTimeImmutable('2026-11-19T12:00:00Z', new DateTimeZone('UTC'));
        $innerStorage = new InMemoryDocumentStorage($clock);

        $rBarrier = $this->renderService()->requestRender(new RequestDocumentRender(
            scope: $this->docScope(),
            renderRequestRef: 'rreq-race-first',
            legalDocumentRef: $legalRef,
            templateId: 'tmpl-a',
            templateVersion: '1.0',
            templateDigest: self::digest('a'),
            locale: 'en-US',
            rendererName: 'in-memory-pdf',
            rendererVersion: '1.0.0',
            rendererDigest: self::digest('r'),
        ));
        $rRetry = $this->renderService()->requestRender(new RequestDocumentRender(
            scope: $this->docScope(),
            renderRequestRef: 'rreq-race-retry',
            legalDocumentRef: $legalRef,
            templateId: 'tmpl-b',
            templateVersion: '1.0',
            templateDigest: self::digest('b'),
            locale: 'en-US',
            rendererName: 'in-memory-pdf',
            rendererVersion: '1.0.0',
            rendererDigest: self::digest('r'),
        ));

        $firstBytes = '%PDF-1.4 stale-orphan-first';
        $racedBytes = '%PDF-1.4 retried-live-render';
        $firstBlob = $innerStorage->store(new StoreRenderBlob(
            sellerRef: 'seller-a',
            environment: 'test',
            uploadKey: 'race-orphan-1',
            contentType: 'application/pdf',
            bytes: $firstBytes,
            outputDigest: 'sha256:'.hash('sha256', $firstBytes),
        ));
        $racedBlob = $innerStorage->store(new StoreRenderBlob(
            sellerRef: 'seller-a',
            environment: 'test',
            uploadKey: 'race-orphan-2',
            contentType: 'application/pdf',
            bytes: $racedBytes,
            outputDigest: 'sha256:'.hash('sha256', $racedBytes),
        ));

        $nowStr = $clock->format('Y-m-d\TH:i:s.u\Z');
        $this->owner()->insert(
            'INSERT INTO public.billium_document_render_blob (seller_ref, environment, upload_key, blob_ref, render_request_id, output_digest, content_type, byte_size, status, retention_pinned_until, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'seller-a', 'test', 'race-orphan-1', $firstBlob->blobRef, $rBarrier->id, $firstBlob->outputDigest, 'application/pdf', $firstBlob->byteSize, 'orphaned', null, $nowStr, $nowStr,
                'seller-a', 'test', 'race-orphan-2', $racedBlob->blobRef, $rRetry->id, $racedBlob->outputDigest, 'application/pdf', $racedBlob->byteSize, 'orphaned', null, $nowStr, $nowStr,
            ],
        );

        // Storage barrier double: when cleanup deletes firstBlob (after selecting BOTH orphans),
        // simulate a concurrent render retry attaching racedBlob BEFORE cleanup processes racedBlob.
        $barrierTriggered = false;
        $onFirstDelete = function () use (&$barrierTriggered, $rRetry, $racedBlob): void {
            $barrierTriggered = true;
            $attached = $this->renderService()->attachRenderResult(new AttachRenderResult(
                scope: $this->docScope(),
                renderRequestRef: 'rreq-race-retry',
                renderRevision: $rRetry->renderRevision,
                renderKeyDigest: $rRetry->renderKeyDigest,
                blobRef: $racedBlob->blobRef,
                outputDigest: $racedBlob->outputDigest,
                contentType: 'application/pdf',
                byteSize: $racedBlob->byteSize,
            ));
            self::assertTrue($attached->attached);
            self::assertSame(RenderState::Ready, $attached->render->state);
        };

        $barrierStorage = new class($innerStorage, $firstBlob->blobRef, $onFirstDelete) implements DocumentStorage
        {
            /** @param Closure(): void $onFirstDelete */
            public function __construct(
                private InMemoryDocumentStorage $inner,
                private string $triggerBlobRef,
                private Closure $onFirstDelete,
            ) {}

            public function store(StoreRenderBlob $command): StoredBlob
            {
                return $this->inner->store($command);
            }

            public function issueReadGrant(ReadBlobGrantRequest $request): TemporaryBlobGrant
            {
                return $this->inner->issueReadGrant($request);
            }

            public function deleteOrphan(string $sellerRef, string $environment, string $blobRef): bool
            {
                $deleted = $this->inner->deleteOrphan($sellerRef, $environment, $blobRef);
                if ($blobRef === $this->triggerBlobRef) {
                    ($this->onFirstDelete)();
                }

                return $deleted;
            }
        };
        $this->application()->instance(DocumentStorage::class, $barrierStorage);

        $cleanup = $this->renderService()->cleanupOrphanBlobs($this->docScope(), $clock);

        self::assertTrue($barrierTriggered);
        self::assertSame(1, $cleanup->deletedCount);
        self::assertSame([$firstBlob->blobRef], $cleanup->deletedBlobRefs);
        self::assertFalse($innerStorage->isBlobAlive($firstBlob->blobRef));
        self::assertTrue($innerStorage->isBlobAlive($racedBlob->blobRef));
        self::assertSame('deleted', $this->value('SELECT status FROM billium_document_render_blob WHERE blob_ref=?', [$firstBlob->blobRef]));
        self::assertSame('attached', $this->value('SELECT status FROM billium_document_render_blob WHERE blob_ref=?', [$racedBlob->blobRef]));

        $read = $this->renderService()->readRender(new ReadDocumentRender(
            scope: $this->docScope(),
            legalDocumentRef: $legalRef,
            templateId: 'tmpl-b',
            locale: 'en-US',
        ));
        self::assertSame($racedBlob->blobRef, $read->blobRef);
        self::assertSame($racedBytes, $innerStorage->fetchByTemporaryUrl($read->temporaryUrl, $clock->modify('+10 seconds')));

        // Reverse race: attempting to attach a blob already transitioned to deleted must be refused.
        $rDeleted = $this->renderService()->requestRender(new RequestDocumentRender(
            scope: $this->docScope(),
            renderRequestRef: 'rreq-race-deleted',
            legalDocumentRef: $legalRef,
            templateId: 'tmpl-c',
            templateVersion: '1.0',
            templateDigest: self::digest('c'),
            locale: 'en-US',
            rendererName: 'in-memory-pdf',
            rendererVersion: '1.0.0',
            rendererDigest: self::digest('r'),
        ));

        try {
            $this->renderService()->attachRenderResult(new AttachRenderResult(
                scope: $this->docScope(),
                renderRequestRef: 'rreq-race-deleted',
                renderRevision: $rDeleted->renderRevision,
                renderKeyDigest: $rDeleted->renderKeyDigest,
                blobRef: $firstBlob->blobRef,
                outputDigest: $firstBlob->outputDigest,
                contentType: 'application/pdf',
                byteSize: $firstBlob->byteSize,
            ));
            self::fail('Attaching an already-deleted blob must be refused');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::RecoveryRequired, $error->problem);
            self::assertSame('billium.documents.render_blob_deleted', $error->detail);
        }

        self::assertSame(RenderState::Pending->value, $this->value("SELECT state FROM billium_document_render_request WHERE render_request_ref='rreq-race-deleted'"));
    }

    public function test_orphan_cleanup_retries_deleting_blobs_after_storage_false_or_exception_while_fencing_attach(): void
    {
        $this->registerCompleteProfile();
        $this->registerSeries();
        $fin = $this->prepareFinalizedInvoiceBasis(suffix: 'rnd-f10');
        $issued = $this->issuer()->issueInvoiceDocument($this->issueCommand('cmd-rnd-f10', 'rnd-f10', $fin->approvedSnapshotDigest ?? ''));
        $legalRef = (string) ($issued->body['legalDocumentRef'] ?? '');

        $clock = new DateTimeImmutable('2026-11-19T12:00:00Z', new DateTimeZone('UTC'));
        $innerStorage = new InMemoryDocumentStorage($clock);

        $rFalse = $this->renderService()->requestRender(new RequestDocumentRender(
            scope: $this->docScope(),
            renderRequestRef: 'rreq-f10-false',
            legalDocumentRef: $legalRef,
            templateId: 'tmpl-false',
            templateVersion: '1.0',
            templateDigest: self::digest('a'),
            locale: 'en-US',
            rendererName: 'in-memory-pdf',
            rendererVersion: '1.0.0',
            rendererDigest: self::digest('r'),
        ));
        $rThrow = $this->renderService()->requestRender(new RequestDocumentRender(
            scope: $this->docScope(),
            renderRequestRef: 'rreq-f10-throw',
            legalDocumentRef: $legalRef,
            templateId: 'tmpl-throw',
            templateVersion: '1.0',
            templateDigest: self::digest('b'),
            locale: 'en-US',
            rendererName: 'in-memory-pdf',
            rendererVersion: '1.0.0',
            rendererDigest: self::digest('r'),
        ));

        $falseBytes = '%PDF-1.4 orphan-returns-false';
        $throwBytes = '%PDF-1.4 orphan-throws-once';
        $falseBlob = $innerStorage->store(new StoreRenderBlob(
            sellerRef: 'seller-a',
            environment: 'test',
            uploadKey: 'f10-orphan-false',
            contentType: 'application/pdf',
            bytes: $falseBytes,
            outputDigest: 'sha256:'.hash('sha256', $falseBytes),
        ));
        $throwBlob = $innerStorage->store(new StoreRenderBlob(
            sellerRef: 'seller-a',
            environment: 'test',
            uploadKey: 'f10-orphan-throw',
            contentType: 'application/pdf',
            bytes: $throwBytes,
            outputDigest: 'sha256:'.hash('sha256', $throwBytes),
        ));

        $nowStr = $clock->format('Y-m-d\TH:i:s.u\Z');
        $this->owner()->insert(
            'INSERT INTO public.billium_document_render_blob (seller_ref, environment, upload_key, blob_ref, render_request_id, output_digest, content_type, byte_size, status, retention_pinned_until, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'seller-a', 'test', 'f10-orphan-false', $falseBlob->blobRef, $rFalse->id, $falseBlob->outputDigest, 'application/pdf', $falseBlob->byteSize, 'orphaned', null, $nowStr, $nowStr,
                'seller-a', 'test', 'f10-orphan-throw', $throwBlob->blobRef, $rThrow->id, $throwBlob->outputDigest, 'application/pdf', $throwBlob->byteSize, 'orphaned', null, $nowStr, $nowStr,
            ],
        );

        // Storage double: attempt 1 returns false for falseBlob and throws for throwBlob without removing either object;
        // attempt 2 succeeds for both.
        $flakyStorage = new class($innerStorage, $falseBlob->blobRef, $throwBlob->blobRef) implements DocumentStorage
        {
            /** @var array<string, int> */
            public array $attempts = [];

            public function __construct(
                private InMemoryDocumentStorage $inner,
                private string $falseFirstRef,
                private string $throwFirstRef,
            ) {}

            public function store(StoreRenderBlob $command): StoredBlob
            {
                return $this->inner->store($command);
            }

            public function issueReadGrant(ReadBlobGrantRequest $request): TemporaryBlobGrant
            {
                return $this->inner->issueReadGrant($request);
            }

            public function deleteOrphan(string $sellerRef, string $environment, string $blobRef): bool
            {
                $this->attempts[$blobRef] = ($this->attempts[$blobRef] ?? 0) + 1;
                if ($this->attempts[$blobRef] === 1) {
                    if ($blobRef === $this->falseFirstRef) {
                        return false;
                    }
                    if ($blobRef === $this->throwFirstRef) {
                        throw new RuntimeException('transient storage delete outage');
                    }
                }

                return $this->inner->deleteOrphan($sellerRef, $environment, $blobRef);
            }
        };
        $this->application()->instance(DocumentStorage::class, $flakyStorage);

        // Pass 1: both rows transition to deleting, neither is marked deleted, both remain alive in storage.
        $pass1 = $this->renderService()->cleanupOrphanBlobs($this->docScope(), $clock);
        self::assertSame(0, $pass1->deletedCount);
        self::assertSame([], $pass1->deletedBlobRefs);
        self::assertTrue($innerStorage->isBlobAlive($falseBlob->blobRef));
        self::assertTrue($innerStorage->isBlobAlive($throwBlob->blobRef));
        self::assertSame('deleting', $this->value('SELECT status FROM billium_document_render_blob WHERE blob_ref=?', [$falseBlob->blobRef]));
        self::assertSame('deleting', $this->value('SELECT status FROM billium_document_render_blob WHERE blob_ref=?', [$throwBlob->blobRef]));

        // While in deleting state, concurrent attachRenderResult is strictly fenced and refuses both blobs.
        foreach ([[$rFalse, $falseBlob], [$rThrow, $throwBlob]] as [$req, $blob]) {
            try {
                $this->renderService()->attachRenderResult(new AttachRenderResult(
                    scope: $this->docScope(),
                    renderRequestRef: $req->renderRequestRef,
                    renderRevision: $req->renderRevision,
                    renderKeyDigest: $req->renderKeyDigest,
                    blobRef: $blob->blobRef,
                    outputDigest: $blob->outputDigest,
                    contentType: 'application/pdf',
                    byteSize: $blob->byteSize,
                ));
                self::fail('Attaching a deleting blob must be refused');
            } catch (ProblemException $error) {
                self::assertSame(ProblemCode::RecoveryRequired, $error->problem);
                self::assertSame('billium.documents.render_blob_deleted', $error->detail);
            }
        }
        self::assertSame(RenderState::Pending->value, $this->value("SELECT state FROM billium_document_render_request WHERE render_request_ref='rreq-f10-false'"));
        self::assertSame(RenderState::Pending->value, $this->value("SELECT state FROM billium_document_render_request WHERE render_request_ref='rreq-f10-throw'"));

        // Pass 2: cleanup retries both deleting rows, removes both objects from storage, and finalizes them as deleted.
        $pass2 = $this->renderService()->cleanupOrphanBlobs($this->docScope(), $clock);
        self::assertSame(2, $pass2->deletedCount);
        self::assertSame([$falseBlob->blobRef, $throwBlob->blobRef], $pass2->deletedBlobRefs);
        self::assertFalse($innerStorage->isBlobAlive($falseBlob->blobRef));
        self::assertFalse($innerStorage->isBlobAlive($throwBlob->blobRef));
        self::assertSame('deleted', $this->value('SELECT status FROM billium_document_render_blob WHERE blob_ref=?', [$falseBlob->blobRef]));
        self::assertSame('deleted', $this->value('SELECT status FROM billium_document_render_blob WHERE blob_ref=?', [$throwBlob->blobRef]));
        self::assertSame(2, $flakyStorage->attempts[$falseBlob->blobRef]);
        self::assertSame(2, $flakyStorage->attempts[$throwBlob->blobRef]);
    }

    public function test_cleanup_orphan_blobs_rejects_open_transaction_for_deleting_blob_with_zero_storage_calls(): void
    {
        $this->registerCompleteProfile();
        $this->registerSeries();
        $fin = $this->prepareFinalizedInvoiceBasis(suffix: 'rnd-f13');
        $issued = $this->issuer()->issueInvoiceDocument($this->issueCommand('cmd-rnd-f13', 'rnd-f13', $fin->approvedSnapshotDigest ?? ''));
        $legalRef = (string) ($issued->body['legalDocumentRef'] ?? '');

        $clock = new DateTimeImmutable('2026-11-19T12:00:00Z', new DateTimeZone('UTC'));
        $innerStorage = new InMemoryDocumentStorage($clock);

        $req = $this->renderService()->requestRender(new RequestDocumentRender(
            scope: $this->docScope(),
            renderRequestRef: 'rreq-f13',
            legalDocumentRef: $legalRef,
            templateId: 'tmpl-f13',
            templateVersion: '1.0',
            templateDigest: self::digest('f'),
            locale: 'en-US',
            rendererName: 'in-memory-pdf',
            rendererVersion: '1.0.0',
            rendererDigest: self::digest('r'),
        ));

        $bytes = '%PDF-1.4 orphan-deleting-tx-guard';
        $blob = $innerStorage->store(new StoreRenderBlob(
            sellerRef: 'seller-a',
            environment: 'test',
            uploadKey: 'f13-orphan',
            contentType: 'application/pdf',
            bytes: $bytes,
            outputDigest: 'sha256:'.hash('sha256', $bytes),
        ));

        $nowStr = $clock->format('Y-m-d\TH:i:s.u\Z');
        $this->owner()->insert(
            'INSERT INTO public.billium_document_render_blob (seller_ref, environment, upload_key, blob_ref, render_request_id, output_digest, content_type, byte_size, status, retention_pinned_until, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['seller-a', 'test', 'f13-orphan', $blob->blobRef, $req->id, $blob->outputDigest, 'application/pdf', $blob->byteSize, 'orphaned', null, $nowStr, $nowStr],
        );

        $countingStorage = new class($innerStorage) implements DocumentStorage
        {
            public bool $failNext = true;

            public int $deleteCalls = 0;

            public function __construct(private InMemoryDocumentStorage $inner) {}

            public function store(StoreRenderBlob $command): StoredBlob
            {
                return $this->inner->store($command);
            }

            public function issueReadGrant(ReadBlobGrantRequest $request): TemporaryBlobGrant
            {
                return $this->inner->issueReadGrant($request);
            }

            public function deleteOrphan(string $sellerRef, string $environment, string $blobRef): bool
            {
                $this->deleteCalls++;
                if ($this->failNext) {
                    $this->failNext = false;

                    return false;
                }

                return $this->inner->deleteOrphan($sellerRef, $environment, $blobRef);
            }
        };
        $this->application()->instance(DocumentStorage::class, $countingStorage);

        // Pass 1 fails storage deletion, leaving the row in 'deleting'.
        $first = $this->renderService()->cleanupOrphanBlobs($this->docScope(), $clock);
        self::assertSame(0, $first->deletedCount);
        self::assertSame(1, $countingStorage->deleteCalls);
        self::assertSame('deleting', $this->value('SELECT status FROM billium_document_render_blob WHERE blob_ref=?', [$blob->blobRef]));

        // Invoking cleanupOrphanBlobs inside an open Billium transaction MUST fail closed with 0 storage calls.
        $countingStorage->deleteCalls = 0;
        /** @var OperationRunner $runner */
        $runner = $this->application()->make(OperationRunner::class);
        try {
            $runner->run(
                spec: new OperationSpec(
                    commandKind: 'documents.test.open_tx_cleanup_guard.v1',
                    sellerRef: 'seller-a',
                    environment: 'test',
                    mode: FinancialMode::Administrative,
                    operationClass: ModuleOperationClass::NewIntent,
                    modules: ['platform', 'ledger', 'documents'],
                ),
                body: function (TransactionContext $context) use ($clock): Receipt {
                    $this->renderService()->cleanupOrphanBlobs($this->docScope(), $clock);
                    self::fail('cleanupOrphanBlobs must throw LogicException inside an open transaction');
                },
            );
            self::fail('Open transaction must reject cleanupOrphanBlobs');
        } catch (LogicException $error) {
            self::assertStringContainsString('outside every database transaction', $error->getMessage());
        }

        self::assertSame(0, $countingStorage->deleteCalls);
        self::assertTrue($innerStorage->isBlobAlive($blob->blobRef));
        self::assertSame('deleting', $this->value('SELECT status FROM billium_document_render_blob WHERE blob_ref=?', [$blob->blobRef]));

        // Outside the transaction, cleanup retries cleanly and transitions deleting -> deleted.
        $retried = $this->renderService()->cleanupOrphanBlobs($this->docScope(), $clock);
        self::assertSame(1, $retried->deletedCount);
        self::assertSame([$blob->blobRef], $retried->deletedBlobRefs);
        self::assertSame(1, $countingStorage->deleteCalls);
        self::assertFalse($innerStorage->isBlobAlive($blob->blobRef));
        self::assertSame('deleted', $this->value('SELECT status FROM billium_document_render_blob WHERE blob_ref=?', [$blob->blobRef]));
    }

    public function test_orphan_cleanup_recovers_deleting_blob_already_absent_in_storage(): void
    {
        $this->registerCompleteProfile();
        $this->registerSeries();
        $fin = $this->prepareFinalizedInvoiceBasis(suffix: 'rnd-abs');
        $issued = $this->issuer()->issueInvoiceDocument($this->issueCommand('cmd-rnd-abs', 'rnd-abs', $fin->approvedSnapshotDigest ?? ''));
        $legalRef = (string) ($issued->body['legalDocumentRef'] ?? '');

        $clock = new DateTimeImmutable('2026-11-19T12:00:00Z', new DateTimeZone('UTC'));
        $storage = new InMemoryDocumentStorage($clock);
        $this->application()->instance(DocumentStorage::class, $storage);

        $req = $this->renderService()->requestRender(new RequestDocumentRender(
            scope: $this->docScope(),
            renderRequestRef: 'rreq-abs',
            legalDocumentRef: $legalRef,
            templateId: 'tmpl-abs',
            templateVersion: '1.0',
            templateDigest: self::digest('a'),
            locale: 'en-US',
            rendererName: 'in-memory-pdf',
            rendererVersion: '1.0.0',
            rendererDigest: self::digest('r'),
        ));

        // Simulate crash after storage deleted the blob before DB deleting -> deleted committed:
        // (1) blob-already-absent does not exist in storage at all;
        // (2) alreadyDeletedBlob was already deleted in storage before the crash;
        // (3) sellerALiveBlob verifies cross-seller deleteOrphan still returns false.
        $alreadyDeletedBytes = '%PDF-1.4 already-deleted-before-crash';
        $alreadyDeletedBlob = $storage->store(new StoreRenderBlob(
            sellerRef: 'seller-a',
            environment: 'test',
            uploadKey: 'abs-already-deleted',
            contentType: 'application/pdf',
            bytes: $alreadyDeletedBytes,
            outputDigest: 'sha256:'.hash('sha256', $alreadyDeletedBytes),
        ));
        self::assertTrue($storage->deleteOrphan('seller-a', 'test', $alreadyDeletedBlob->blobRef));

        $liveBytes = '%PDF-1.4 seller-a-live';
        $sellerALiveBlob = $storage->store(new StoreRenderBlob(
            sellerRef: 'seller-a',
            environment: 'test',
            uploadKey: 'abs-live',
            contentType: 'application/pdf',
            bytes: $liveBytes,
            outputDigest: 'sha256:'.hash('sha256', $liveBytes),
        ));
        self::assertFalse($storage->deleteOrphan('seller-b', 'test', $sellerALiveBlob->blobRef));
        self::assertTrue($storage->isBlobAlive($sellerALiveBlob->blobRef));

        $nowStr = $clock->format('Y-m-d\TH:i:s.u\Z');
        $absentRef = 'blob-already-absent-in-storage';
        $this->owner()->insert(
            'INSERT INTO public.billium_document_render_blob (seller_ref, environment, upload_key, blob_ref, render_request_id, output_digest, content_type, byte_size, status, retention_pinned_until, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'seller-a', 'test', 'abs-missing', $absentRef, $req->id, 'sha256:'.hash('sha256', 'absent'), 'application/pdf', 12, 'deleting', null, $nowStr, $nowStr,
                'seller-a', 'test', 'abs-already-deleted', $alreadyDeletedBlob->blobRef, $req->id, $alreadyDeletedBlob->outputDigest, 'application/pdf', $alreadyDeletedBlob->byteSize, 'deleting', null, $nowStr, $nowStr,
            ],
        );

        $cleanup = $this->renderService()->cleanupOrphanBlobs($this->docScope(), $clock);
        self::assertSame(2, $cleanup->deletedCount);
        self::assertSame([$absentRef, $alreadyDeletedBlob->blobRef], $cleanup->deletedBlobRefs);
        self::assertSame('deleted', $this->value('SELECT status FROM billium_document_render_blob WHERE blob_ref=?', [$absentRef]));
        self::assertSame('deleted', $this->value('SELECT status FROM billium_document_render_blob WHERE blob_ref=?', [$alreadyDeletedBlob->blobRef]));
        self::assertTrue($storage->isBlobAlive($sellerALiveBlob->blobRef));
    }
}
