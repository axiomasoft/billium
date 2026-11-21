<?php

declare(strict_types=1);

namespace Billium\Documents\Internal\Services;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\BilliumQuery;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Documents\Contracts\DocumentRenderer;
use Billium\Documents\Contracts\DocumentRenderService;
use Billium\Documents\Contracts\DocumentStorage;
use Billium\Documents\Data\AttachRenderResult;
use Billium\Documents\Data\DocumentRenderView;
use Billium\Documents\Data\DocumentScope;
use Billium\Documents\Data\OrphanCleanupResult;
use Billium\Documents\Data\ReadBlobGrantRequest;
use Billium\Documents\Data\ReadDocumentRender;
use Billium\Documents\Data\ReadRenderResult;
use Billium\Documents\Data\RenderAttachResult;
use Billium\Documents\Data\RenderDocumentInput;
use Billium\Documents\Data\RenderedArtifact;
use Billium\Documents\Data\RequestDocumentRender;
use Billium\Documents\Data\StoreRenderBlob;
use Billium\Documents\Enums\RenderState;
use Billium\Documents\Internal\Canonical\CanonicalJson;
use Billium\Documents\Internal\DocumentReceipt;
use Billium\Documents\Internal\Support\RowCast;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;
use PDO;
use stdClass;
use Throwable;

#[Internal]
final readonly class DatabaseDocumentRenderService implements DocumentRenderService
{
    /** @var list<string> */
    private const array SENSITIVE_DIAGNOSTIC_KEYS = [
        'token',
        'secret',
        'password',
        'signature',
        'sig',
        'authorization',
        'api_key',
        'apikey',
        'bearer',
        'credential',
    ];

    /**
     * @param  Closure(): ?DocumentRenderer  $rendererResolver
     * @param  Closure(): ?DocumentStorage  $storageResolver
     */
    public function __construct(
        private DatabaseManager $databases,
        private OperationRunner $runner,
        private CallerContext $callers,
        private BilliumOptions $options,
        private Closure $rendererResolver,
        private Closure $storageResolver,
    ) {}

    public function requestRender(RequestDocumentRender $command): DocumentRenderView
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->renderRequestRef, 'renderRequestRef');
        RowCast::bounded($command->legalDocumentRef, 'legalDocumentRef');
        RowCast::bounded($command->templateId, 'templateId', 120);
        RowCast::bounded($command->templateVersion, 'templateVersion', 64);
        RowCast::bounded($command->templateDigest, 'templateDigest');
        RowCast::bounded($command->locale, 'locale', 32);
        RowCast::bounded($command->rendererName, 'rendererName', 120);
        RowCast::bounded($command->rendererVersion, 'rendererVersion', 64);
        RowCast::bounded($command->rendererDigest, 'rendererDigest');

        $legalRow = $this->databases->connection($this->options->connection)->table('billium_document_legal')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('legal_document_ref', $command->legalDocumentRef)
            ->first();

        if ($legalRow === null) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.documents.legal_document_not_found');
        }

        $legalId = RowCast::integer($legalRow->id, 'id');
        $docRevision = RowCast::integer($legalRow->document_revision, 'document_revision');
        $approvedSnapshotDigest = RowCast::str($legalRow->approved_snapshot_digest, 'approved_snapshot_digest');

        $renderKeyDigest = CanonicalJson::digest([
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'legalDocumentRef' => $command->legalDocumentRef,
            'documentRevision' => $docRevision,
            'approvedSnapshotDigest' => $approvedSnapshotDigest,
            'templateId' => $command->templateId,
            'templateVersion' => $command->templateVersion,
            'templateDigest' => $command->templateDigest,
            'locale' => $command->locale,
            'rendererName' => $command->rendererName,
            'rendererVersion' => $command->rendererVersion,
            'rendererDigest' => $command->rendererDigest,
            'assetRefs' => $command->assetRefs,
        ]);

        $sanitizedAssets = self::sanitizeAssetList($command->assetRefs);
        $initialDiagnostics = [
            'assetRefs' => $sanitizedAssets,
            'retentionPinnedUntil' => $command->retentionPinnedUntil?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        ];

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'documents.render.request.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Administrative,
                operationClass: ModuleOperationClass::NewIntent,
                modules: ['platform', 'ledger', 'documents'],
            ),
            body: function (TransactionContext $context) use ($caller, $command, $legalId, $docRevision, $approvedSnapshotDigest, $renderKeyDigest, $initialDiagnostics): DocumentReceipt {
                $this->runner->assertCurrent($context, 'documents');
                $db = $this->databases->connection($context->connectionName());
                $now = self::nowUtc();

                $byKey = $db->table('billium_document_render_request')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('render_key_digest', $renderKeyDigest)
                    ->first();

                if ($byKey !== null) {
                    return new DocumentReceipt('documents.render.requested', [
                        'id' => RowCast::integer($byKey->id, 'id'),
                    ]);
                }

                $byRef = $db->table('billium_document_render_request')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('render_request_ref', $command->renderRequestRef)
                    ->first();

                if ($byRef !== null) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.render_request_conflict');
                }

                $maxRevRaw = $db->table('billium_document_render_request')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('legal_document_id', $legalId)
                    ->where('template_id', $command->templateId)
                    ->where('locale', $command->locale)
                    ->max('render_revision');

                $nextRev = ($maxRevRaw !== null ? RowCast::integer($maxRevRaw, 'render_revision') : 0) + 1;

                // Supersede older incomplete render requests when a new render revision is requested.
                if ($nextRev > 1) {
                    $db->table('billium_document_render_request')
                        ->where('seller_ref', $caller->sellerRef)
                        ->where('environment', $caller->environment)
                        ->where('legal_document_id', $legalId)
                        ->where('template_id', $command->templateId)
                        ->where('locale', $command->locale)
                        ->whereIn('state', [RenderState::Pending->value, RenderState::Rendering->value, RenderState::Failed->value])
                        ->update([
                            'state' => RenderState::Superseded->value,
                            'updated_at' => $now,
                        ]);
                }

                $id = $db->table('billium_document_render_request')->insertGetId([
                    'seller_ref' => $caller->sellerRef,
                    'environment' => $caller->environment,
                    'render_request_ref' => $command->renderRequestRef,
                    'legal_document_id' => $legalId,
                    'legal_document_ref' => $command->legalDocumentRef,
                    'document_revision' => $docRevision,
                    'approved_snapshot_digest' => $approvedSnapshotDigest,
                    'template_id' => $command->templateId,
                    'template_version' => $command->templateVersion,
                    'template_digest' => $command->templateDigest,
                    'locale' => $command->locale,
                    'renderer_name' => $command->rendererName,
                    'renderer_version' => $command->rendererVersion,
                    'renderer_digest' => $command->rendererDigest,
                    'render_key_digest' => $renderKeyDigest,
                    'render_revision' => $nextRev,
                    'attempt' => 0,
                    'state' => RenderState::Pending->value,
                    'blob_ref' => null,
                    'output_digest' => null,
                    'content_type' => null,
                    'byte_size' => null,
                    'redacted_diagnostics' => CanonicalJson::encodeObject($initialDiagnostics),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return new DocumentReceipt('documents.render.requested', ['id' => $id]);
            },
        );

        $id = RowCast::integer($outcome->body['id'] ?? null, 'id');

        return $this->requireRenderById($id, $caller->sellerRef, $caller->environment);
    }

    /** @param list<string> $allowedAssetPrefixes */
    public function executeRender(
        DocumentScope $scope,
        string $renderRequestRef,
        array $allowedAssetPrefixes = ['asset://'],
    ): RenderAttachResult {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($renderRequestRef, 'renderRequestRef');

        $this->assertOutsideTransaction();

        $renderer = ($this->rendererResolver)();
        if ($renderer === null) {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'billium.documents.renderer_unbound');
        }
        $storage = ($this->storageResolver)();
        if ($storage === null) {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'billium.documents.storage_unbound');
        }

        // Tx 1: Validate request, check asset allow-list / renderer version, and transition to rendering.
        $prep = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'documents.render.start.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Administrative,
                operationClass: ModuleOperationClass::NewIntent,
                modules: ['platform', 'ledger', 'documents'],
            ),
            body: function (TransactionContext $context) use ($caller, $renderRequestRef): DocumentReceipt {
                $this->runner->assertCurrent($context, 'documents');
                $db = $this->databases->connection($context->connectionName());
                $now = self::nowUtc();

                $req = $db->table('billium_document_render_request')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('render_request_ref', $renderRequestRef)
                    ->first();

                if ($req === null) {
                    throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.documents.render_request_not_found');
                }

                $state = RowCast::str($req->state, 'state');
                $reqId = RowCast::integer($req->id, 'id');
                if ($state === RenderState::Superseded->value) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.render_superseded');
                }
                if ($state === RenderState::Ready->value) {
                    return new DocumentReceipt('documents.render.already_ready', ['alreadyReady' => true, 'id' => $reqId]);
                }

                $attempt = RowCast::integer($req->attempt, 'attempt') + 1;
                $db->table('billium_document_render_request')
                    ->where('id', $reqId)
                    ->update([
                        'state' => RenderState::Rendering->value,
                        'attempt' => $attempt,
                        'updated_at' => $now,
                    ]);

                return new DocumentReceipt('documents.render.started', ['alreadyReady' => false, 'id' => $reqId]);
            },
        );

        $reqId = RowCast::integer($prep->body['id'] ?? null, 'id');
        $alreadyReady = RowCast::boolean($prep->body['alreadyReady'] ?? null, 'alreadyReady');
        $reqView = $this->requireRenderById($reqId, $caller->sellerRef, $caller->environment);
        if ($alreadyReady) {
            return new RenderAttachResult(attached: false, staleIgnored: false, render: $reqView);
        }

        if ($renderer->rendererName() !== $reqView->rendererName || $renderer->rendererVersion() !== $reqView->rendererVersion) {
            $this->recordRenderFailure($caller->sellerRef, $caller->environment, $reqId, ['error' => 'renderer_version_mismatch']);

            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.renderer_version_mismatch');
        }

        /** @var list<string> $requestedAssets */
        $requestedAssets = is_array($reqView->redactedDiagnostics['assetRefs'] ?? null)
            ? array_values(array_filter($reqView->redactedDiagnostics['assetRefs'], is_string(...)))
            : [];

        $this->assertAssetsAllowed($caller->sellerRef, $caller->environment, $reqId, $requestedAssets, $allowedAssetPrefixes);

        $legalRow = $this->databases->connection($this->options->connection)->table('billium_document_legal')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('legal_document_ref', $reqView->legalDocumentRef)
            ->first();

        if ($legalRow === null) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.documents.legal_document_not_found');
        }

        $legal = DatabaseLegalDocumentIssuer::hydrate($legalRow);
        $renderInput = new RenderDocumentInput(
            sellerRef: $legal->sellerRef,
            environment: $legal->environment,
            legalDocumentRef: $legal->legalDocumentRef,
            documentRevision: $legal->documentRevision,
            legalNumber: $legal->legalNumber,
            documentType: $legal->documentType,
            approvedSnapshotDigest: $legal->approvedSnapshotDigest,
            templateId: $reqView->templateId,
            templateVersion: $reqView->templateVersion,
            templateDigest: $reqView->templateDigest,
            locale: $reqView->locale,
            currency: $legal->currency,
            subtotalMinor: $legal->subtotalMinor,
            taxMinor: $legal->taxMinor,
            totalMinor: $legal->totalMinor,
            sellerSnapshot: $legal->sellerSnapshot,
            buyerSnapshot: $legal->buyerSnapshot,
            taxSnapshot: $legal->taxSnapshot,
            termsSnapshot: $legal->termsSnapshot,
            linesSnapshot: $legal->linesSnapshot,
            displayMetadata: $legal->displayMetadata,
            assetRefs: $requestedAssets,
        );

        // External Renderer call strictly outside DB transaction.
        try {
            $artifact = $renderer->render($renderInput);
        } catch (Throwable $error) {
            $this->recordRenderFailure($caller->sellerRef, $caller->environment, $reqId, ['error' => $error::class]);
            if ($error instanceof ProblemException) {
                throw $error;
            }

            throw new ProblemException(ProblemCode::TransportUnavailable, 'billium.documents.renderer_failed');
        }

        $this->assertArtifactValid($caller->sellerRef, $caller->environment, $reqId, $artifact, $allowedAssetPrefixes);

        $retentionRaw = $reqView->redactedDiagnostics['retentionPinnedUntil'] ?? null;
        $retentionPinnedUntil = is_string($retentionRaw) && $retentionRaw !== '' ? new DateTimeImmutable($retentionRaw) : null;
        $uploadKey = 'render:'.hash('sha256', CanonicalJson::encode([
            $caller->sellerRef,
            $caller->environment,
            $reqView->renderRequestRef,
            $reqView->renderKeyDigest,
            $artifact->outputDigest,
        ]));

        // External Storage call strictly outside DB transaction. Deterministic uploadKey ensures retry reuses blobRef.
        try {
            $storedBlob = $storage->store(new StoreRenderBlob(
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                uploadKey: $uploadKey,
                contentType: $artifact->contentType,
                bytes: $artifact->bytes,
                outputDigest: $artifact->outputDigest,
                retentionPinnedUntil: $retentionPinnedUntil,
            ));
        } catch (Throwable $error) {
            $this->recordRenderFailure($caller->sellerRef, $caller->environment, $reqId, [
                'error' => $error::class,
                'uploadKey' => $uploadKey,
            ]);
            if ($error instanceof ProblemException) {
                throw $error;
            }

            throw new ProblemException(ProblemCode::TransportUnavailable, 'billium.documents.storage_failed');
        }

        // Persist uploaded blob metadata idempotently before CAS attach.
        $this->recordUploadedBlob(
            sellerRef: $caller->sellerRef,
            environment: $caller->environment,
            uploadKey: $uploadKey,
            blobRef: $storedBlob->blobRef,
            renderRequestId: $reqId,
            outputDigest: $storedBlob->outputDigest,
            contentType: $storedBlob->contentType,
            byteSize: $storedBlob->byteSize,
            retentionPinnedUntil: $retentionPinnedUntil,
        );

        // Tx 2: CAS attach by (renderRequestRef, renderRevision, renderKeyDigest).
        return $this->attachRenderResult(new AttachRenderResult(
            scope: $scope,
            renderRequestRef: $reqView->renderRequestRef,
            renderRevision: $reqView->renderRevision,
            renderKeyDigest: $reqView->renderKeyDigest,
            blobRef: $storedBlob->blobRef,
            outputDigest: $storedBlob->outputDigest,
            contentType: $storedBlob->contentType,
            byteSize: $storedBlob->byteSize,
            diagnostics: self::redactDiagnostics($artifact->diagnostics),
        ));
    }

    public function attachRenderResult(AttachRenderResult $command): RenderAttachResult
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->renderRequestRef, 'renderRequestRef');
        RowCast::bounded($command->renderKeyDigest, 'renderKeyDigest');
        RowCast::bounded($command->blobRef, 'blobRef');
        RowCast::bounded($command->outputDigest, 'outputDigest');
        RowCast::bounded($command->contentType, 'contentType', 120);

        $redacted = self::redactDiagnostics($command->diagnostics);

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'documents.render.attach.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Administrative,
                operationClass: ModuleOperationClass::NewIntent,
                modules: ['platform', 'ledger', 'documents'],
            ),
            body: function (TransactionContext $context) use ($caller, $command, $redacted): DocumentReceipt {
                $this->runner->assertCurrent($context, 'documents');
                $db = $this->databases->connection($context->connectionName());
                $now = self::nowUtc();

                $target = $db->table('billium_document_render_request')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('render_request_ref', $command->renderRequestRef)
                    ->first();

                if ($target === null) {
                    throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.documents.render_request_not_found');
                }

                $targetId = RowCast::integer($target->id, 'id');
                $targetState = RowCast::str($target->state, 'state');
                $targetRev = RowCast::integer($target->render_revision, 'render_revision');
                $targetDigest = RowCast::str($target->render_key_digest, 'render_key_digest');
                $legalDocId = RowCast::integer($target->legal_document_id, 'legal_document_id');
                $templateId = RowCast::str($target->template_id, 'template_id');
                $locale = RowCast::str($target->locale, 'locale');

                if (
                    $targetState === RenderState::Ready->value
                    && $targetRev === $command->renderRevision
                    && $targetDigest === $command->renderKeyDigest
                    && RowCast::optStr($target->blob_ref, 'blob_ref') === $command->blobRef
                    && RowCast::optStr($target->output_digest, 'output_digest') === $command->outputDigest
                ) {
                    $db->table('billium_document_render_blob')
                        ->where('seller_ref', $caller->sellerRef)
                        ->where('environment', $caller->environment)
                        ->where('blob_ref', $command->blobRef)
                        ->where('status', 'uploaded')
                        ->update(['status' => 'attached', 'render_request_id' => $targetId, 'updated_at' => $now]);

                    return new DocumentReceipt('documents.render.attach_idempotent', [
                        'attached' => false,
                        'staleIgnored' => false,
                        'viewId' => $targetId,
                    ]);
                }

                $latest = $db->table('billium_document_render_request')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('legal_document_id', $legalDocId)
                    ->where('template_id', $templateId)
                    ->where('locale', $locale)
                    ->orderByDesc('render_revision')
                    ->first();

                $latestId = $latest !== null ? RowCast::integer($latest->id, 'id') : $targetId;
                $latestRev = $latest !== null ? RowCast::integer($latest->render_revision, 'render_revision') : $targetRev;

                $isStale = $targetState === RenderState::Superseded->value
                    || $targetRev !== $command->renderRevision
                    || $targetDigest !== $command->renderKeyDigest
                    || $latestRev !== $command->renderRevision;

                if ($isStale) {
                    $db->table('billium_document_render_blob')
                        ->where('seller_ref', $caller->sellerRef)
                        ->where('environment', $caller->environment)
                        ->where('blob_ref', $command->blobRef)
                        ->whereIn('status', ['uploaded'])
                        ->update(['status' => 'orphaned', 'updated_at' => $now]);

                    return new DocumentReceipt('documents.render.attach_stale', [
                        'attached' => false,
                        'staleIgnored' => true,
                        'viewId' => $latestId,
                    ]);
                }

                // Do not mark ready unless the blob row is already stored and still attachable.
                $blobRow = $db->table('billium_document_render_blob')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('blob_ref', $command->blobRef)
                    ->first();
                if ($blobRow === null) {
                    throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.documents.render_blob_not_stored');
                }
                if (in_array(RowCast::str($blobRow->status, 'status'), ['deleting', 'deleted'], true)) {
                    throw new ProblemException(ProblemCode::RecoveryRequired, 'billium.documents.render_blob_deleted');
                }

                $mergedDiagnostics = array_merge(
                    RowCast::jsonObject($target->redacted_diagnostics, 'redacted_diagnostics'),
                    $redacted,
                );

                $affected = $db->table('billium_document_render_request')
                    ->where('id', $targetId)
                    ->whereIn('state', [RenderState::Pending->value, RenderState::Rendering->value])
                    ->where('render_revision', $command->renderRevision)
                    ->where('render_key_digest', $command->renderKeyDigest)
                    ->update([
                        'state' => RenderState::Ready->value,
                        'blob_ref' => $command->blobRef,
                        'output_digest' => $command->outputDigest,
                        'content_type' => $command->contentType,
                        'byte_size' => $command->byteSize,
                        'redacted_diagnostics' => CanonicalJson::encodeObject($mergedDiagnostics),
                        'updated_at' => $now,
                    ]);

                if ($affected === 0) {
                    $db->table('billium_document_render_blob')
                        ->where('seller_ref', $caller->sellerRef)
                        ->where('environment', $caller->environment)
                        ->where('blob_ref', $command->blobRef)
                        ->where('status', 'uploaded')
                        ->update(['status' => 'orphaned', 'updated_at' => $now]);

                    return new DocumentReceipt('documents.render.attach_cas_lost', [
                        'attached' => false,
                        'staleIgnored' => true,
                        'viewId' => $latestId,
                    ]);
                }

                $attachedRows = $db->select(
                    "UPDATE billium_document_render_blob SET status = 'attached', render_request_id = ?, updated_at = ? WHERE seller_ref = ? AND environment = ? AND blob_ref = ? AND status NOT IN ('deleting', 'deleted') RETURNING id",
                    [$targetId, $now, $caller->sellerRef, $caller->environment, $command->blobRef],
                );

                if ($attachedRows === []) {
                    $blockedExists = $db->table('billium_document_render_blob')
                        ->where('seller_ref', $caller->sellerRef)
                        ->where('environment', $caller->environment)
                        ->where('blob_ref', $command->blobRef)
                        ->whereIn('status', ['deleting', 'deleted'])
                        ->exists();

                    if ($blockedExists) {
                        throw new ProblemException(ProblemCode::RecoveryRequired, 'billium.documents.render_blob_deleted');
                    }
                }

                return new DocumentReceipt('documents.render.attached', [
                    'attached' => true,
                    'staleIgnored' => false,
                    'viewId' => $targetId,
                ]);
            },
        );

        return new RenderAttachResult(
            attached: RowCast::boolean($outcome->body['attached'] ?? null, 'attached'),
            staleIgnored: RowCast::boolean($outcome->body['staleIgnored'] ?? null, 'staleIgnored'),
            render: $this->requireRenderById(
                RowCast::integer($outcome->body['viewId'] ?? null, 'viewId'),
                $caller->sellerRef,
                $caller->environment,
            ),
        );
    }

    public function readRender(ReadDocumentRender $command): ReadRenderResult
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->legalDocumentRef, 'legalDocumentRef');
        RowCast::bounded($command->templateId, 'templateId', 120);
        RowCast::bounded($command->locale, 'locale', 32);

        if ($command->allowedActorRefs !== null && ! in_array($caller->actorRef, $command->allowedActorRefs, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'billium.documents.actor_not_authorized');
        }
        if ($command->ttlSeconds < 1 || $command->ttlSeconds > 900) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.render_url_ttl_invalid');
        }

        $this->assertOutsideTransaction();

        $db = $this->databases->connection($this->options->connection);

        $legalExists = $db->table('billium_document_legal')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('legal_document_ref', $command->legalDocumentRef)
            ->exists();

        if (! $legalExists) {
            throw new ProblemException(ProblemCode::AuthDenied, 'billium.documents.render_not_in_scope');
        }

        $query = $db->table('billium_document_render_request')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('legal_document_ref', $command->legalDocumentRef)
            ->where('template_id', $command->templateId)
            ->where('locale', $command->locale)
            ->where('state', RenderState::Ready->value);

        if ($command->renderRequestRef !== null) {
            $query->where('render_request_ref', $command->renderRequestRef);
        }

        $row = $query->orderByDesc('render_revision')->first();
        if ($row === null) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.documents.render_not_ready');
        }

        $storage = ($this->storageResolver)();
        if ($storage === null) {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'billium.documents.storage_unbound');
        }

        $blobRef = RowCast::str($row->blob_ref, 'blob_ref');
        $grant = $storage->issueReadGrant(new ReadBlobGrantRequest(
            sellerRef: $caller->sellerRef,
            environment: $caller->environment,
            blobRef: $blobRef,
            actorRef: $caller->actorRef,
            ttlSeconds: $command->ttlSeconds,
        ));

        return new ReadRenderResult(
            renderRequestRef: RowCast::str($row->render_request_ref, 'render_request_ref'),
            legalDocumentRef: RowCast::str($row->legal_document_ref, 'legal_document_ref'),
            documentRevision: RowCast::integer($row->document_revision, 'document_revision'),
            renderRevision: RowCast::integer($row->render_revision, 'render_revision'),
            blobRef: $blobRef,
            outputDigest: RowCast::str($row->output_digest, 'output_digest'),
            contentType: RowCast::str($row->content_type, 'content_type'),
            byteSize: RowCast::integer($row->byte_size, 'byte_size'),
            temporaryUrl: $grant->temporaryUrl,
            expiresAt: $grant->expiresAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        );
    }

    /**
     * @param  list<string>|null  $allowedActorRefs
     * @return array<string, mixed>
     */
    public function dispatchReadRenderQuery(string $json, ?array $allowedActorRefs = null): array
    {
        $query = (new Codec)->decode($json);
        if (! $query instanceof BilliumQuery || $query->query !== 'readRender') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Expected billium.query.v1 readRender query');
        }
        $caller = $this->callers->current();
        if ($query->installationRef !== $caller->installationRef || $query->sellerRef !== $caller->sellerRef || $query->environment !== $caller->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Query scope differs from authenticated caller');
        }
        $selector = get_object_vars($query->selector);
        $legalDocumentRef = $selector['documentRef'] ?? $selector['legalDocumentRef'] ?? null;
        $templateId = $selector['templateId'] ?? 'default';
        $locale = $selector['locale'] ?? 'en-US';
        $ttlRaw = $selector['ttlSeconds'] ?? null;
        $ttl = 300;
        if ($ttlRaw !== null) {
            if (is_int($ttlRaw)) {
                $ttl = $ttlRaw;
            } elseif (is_string($ttlRaw) && preg_match('/^\d+$/D', $ttlRaw) === 1) {
                $ttl = (int) $ttlRaw;
            } else {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid readRender selector');
            }
        }
        if (! is_string($legalDocumentRef) || $legalDocumentRef === '' || ! is_string($templateId) || ! is_string($locale)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid readRender selector');
        }
        $read = $this->readRender(new ReadDocumentRender(
            scope: new DocumentScope($caller->sellerRef, $caller->environment),
            legalDocumentRef: $legalDocumentRef,
            templateId: $templateId,
            locale: $locale,
            ttlSeconds: $ttl,
            renderRequestRef: is_string($selector['renderRequestRef'] ?? null) ? $selector['renderRequestRef'] : null,
            allowedActorRefs: $allowedActorRefs,
        ));

        return [
            'installationRef' => $caller->installationRef,
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'result' => [
                'renderRequestRef' => $read->renderRequestRef,
                'legalDocumentRef' => $read->legalDocumentRef,
                'documentRevision' => $read->documentRevision,
                'renderRevision' => $read->renderRevision,
                'blobRef' => $read->blobRef,
                'outputDigest' => $read->outputDigest,
                'contentType' => $read->contentType,
                'byteSize' => $read->byteSize,
                'temporaryUrl' => $read->temporaryUrl,
                'expiresAt' => $read->expiresAt,
            ],
        ];
    }

    public function cleanupOrphanBlobs(DocumentScope $scope, DateTimeImmutable $asOf): OrphanCleanupResult
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        $this->assertOutsideTransaction();

        $storage = ($this->storageResolver)();
        if ($storage === null) {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'billium.documents.storage_unbound');
        }

        $candidates = $this->databases->connection($this->options->connection)->table('billium_document_render_blob')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->whereIn('status', ['orphaned', 'deleting'])
            ->orderBy('id')
            ->get();

        $deleted = [];
        $skippedPinned = 0;
        $asOfUtc = $asOf->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');

        foreach ($candidates as $candidate) {
            $status = RowCast::str($candidate->status, 'status');
            $blobRef = RowCast::str($candidate->blob_ref, 'blob_ref');
            $blobId = RowCast::integer($candidate->id, 'id');

            if ($status === 'orphaned') {
                $pinnedRaw = $candidate->retention_pinned_until;
                if ($pinnedRaw !== null && new DateTimeImmutable(RowCast::str($pinnedRaw, 'retention_pinned_until')) > $asOf) {
                    $skippedPinned++;

                    continue;
                }

                $claimOutcome = $this->runner->run(
                    spec: new OperationSpec(
                        commandKind: 'documents.render.cleanup_claim.v1',
                        sellerRef: $caller->sellerRef,
                        environment: $caller->environment,
                        mode: FinancialMode::Administrative,
                        operationClass: ModuleOperationClass::NewIntent,
                        modules: ['platform', 'ledger', 'documents'],
                    ),
                    body: function (TransactionContext $context) use ($caller, $blobId, $blobRef, $asOfUtc): DocumentReceipt {
                        $this->runner->assertCurrent($context, 'documents');
                        $rows = $this->databases->connection($context->connectionName())->select(
                            "UPDATE billium_document_render_blob SET status = 'deleting', updated_at = ? WHERE id = ? AND seller_ref = ? AND environment = ? AND status = 'orphaned' AND (retention_pinned_until IS NULL OR retention_pinned_until <= ?) RETURNING id",
                            [self::nowUtc(), $blobId, $caller->sellerRef, $caller->environment, $asOfUtc],
                        );

                        return new DocumentReceipt('documents.render.orphan_claimed', [
                            'blobRef' => $blobRef,
                            'claimed' => count($rows) === 1,
                        ]);
                    },
                );

                if (! RowCast::boolean($claimOutcome->body['claimed'] ?? null, 'claimed')) {
                    continue;
                }
            }

            $this->assertOutsideTransaction();

            try {
                $storageDeleted = $storage->deleteOrphan($caller->sellerRef, $caller->environment, $blobRef);
            } catch (Throwable) {
                continue;
            }

            if (! $storageDeleted) {
                continue;
            }

            $finalizeOutcome = $this->runner->run(
                spec: new OperationSpec(
                    commandKind: 'documents.render.cleanup_orphan.v1',
                    sellerRef: $caller->sellerRef,
                    environment: $caller->environment,
                    mode: FinancialMode::Administrative,
                    operationClass: ModuleOperationClass::NewIntent,
                    modules: ['platform', 'ledger', 'documents'],
                ),
                body: function (TransactionContext $context) use ($caller, $blobId, $blobRef): DocumentReceipt {
                    $this->runner->assertCurrent($context, 'documents');
                    $rows = $this->databases->connection($context->connectionName())->select(
                        "UPDATE billium_document_render_blob SET status = 'deleted', updated_at = ? WHERE id = ? AND seller_ref = ? AND environment = ? AND status = 'deleting' RETURNING id",
                        [self::nowUtc(), $blobId, $caller->sellerRef, $caller->environment],
                    );

                    return new DocumentReceipt('documents.render.orphan_deleted', [
                        'blobRef' => $blobRef,
                        'finalized' => count($rows) === 1,
                    ]);
                },
            );

            if (RowCast::boolean($finalizeOutcome->body['finalized'] ?? null, 'finalized')) {
                $deleted[] = $blobRef;
            }
        }

        return new OrphanCleanupResult(
            deletedCount: count($deleted),
            skippedPinnedCount: $skippedPinned,
            deletedBlobRefs: $deleted,
        );
    }

    public static function hydrateRender(stdClass $row): DocumentRenderView
    {
        return new DocumentRenderView(
            id: RowCast::integer($row->id, 'id'),
            sellerRef: RowCast::str($row->seller_ref, 'seller_ref'),
            environment: RowCast::str($row->environment, 'environment'),
            renderRequestRef: RowCast::str($row->render_request_ref, 'render_request_ref'),
            legalDocumentRef: RowCast::str($row->legal_document_ref, 'legal_document_ref'),
            documentRevision: RowCast::integer($row->document_revision, 'document_revision'),
            approvedSnapshotDigest: RowCast::str($row->approved_snapshot_digest, 'approved_snapshot_digest'),
            templateId: RowCast::str($row->template_id, 'template_id'),
            templateVersion: RowCast::str($row->template_version, 'template_version'),
            templateDigest: RowCast::str($row->template_digest, 'template_digest'),
            locale: RowCast::str($row->locale, 'locale'),
            rendererName: RowCast::str($row->renderer_name, 'renderer_name'),
            rendererVersion: RowCast::str($row->renderer_version, 'renderer_version'),
            rendererDigest: RowCast::str($row->renderer_digest, 'renderer_digest'),
            renderKeyDigest: RowCast::str($row->render_key_digest, 'render_key_digest'),
            renderRevision: RowCast::integer($row->render_revision, 'render_revision'),
            attempt: RowCast::integer($row->attempt, 'attempt'),
            state: RenderState::from(RowCast::str($row->state, 'state')),
            blobRef: RowCast::optStr($row->blob_ref, 'blob_ref'),
            outputDigest: RowCast::optStr($row->output_digest, 'output_digest'),
            contentType: RowCast::optStr($row->content_type, 'content_type'),
            byteSize: RowCast::optInt($row->byte_size, 'byte_size'),
            redactedDiagnostics: RowCast::jsonObject($row->redacted_diagnostics, 'redacted_diagnostics'),
        );
    }

    /**
     * @param  list<string>  $assets
     * @param  list<string>  $allowedPrefixes
     */
    private function assertAssetsAllowed(
        string $sellerRef,
        string $environment,
        int $reqId,
        array $assets,
        array $allowedPrefixes,
    ): void {
        foreach ($assets as $asset) {
            if (str_contains($asset, '..')) {
                $this->recordRenderFailure($sellerRef, $environment, $reqId, ['blockedAsset' => $asset]);

                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.render_asset_forbidden');
            }
            $allowed = array_any($allowedPrefixes, fn (string $prefix): bool => $prefix !== '' && str_starts_with($asset, $prefix));
            if (! $allowed) {
                $this->recordRenderFailure($sellerRef, $environment, $reqId, ['blockedAsset' => $asset]);

                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.render_asset_forbidden');
            }
        }
    }

    /** @param list<string> $allowedPrefixes */
    private function assertArtifactValid(
        string $sellerRef,
        string $environment,
        int $reqId,
        RenderedArtifact $artifact,
        array $allowedPrefixes,
    ): void {
        $this->assertAssetsAllowed($sellerRef, $environment, $reqId, $artifact->assetRefs, $allowedPrefixes);
        $actualDigest = 'sha256:'.hash('sha256', $artifact->bytes);
        if ($artifact->bytes === '' || $artifact->outputDigest !== $actualDigest) {
            $this->recordRenderFailure($sellerRef, $environment, $reqId, ['error' => 'output_digest_mismatch']);

            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.render_output_digest_mismatch');
        }
    }

    private function recordUploadedBlob(
        string $sellerRef,
        string $environment,
        string $uploadKey,
        string $blobRef,
        int $renderRequestId,
        string $outputDigest,
        string $contentType,
        int $byteSize,
        ?DateTimeImmutable $retentionPinnedUntil,
    ): void {
        $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'documents.render.record_blob.v1',
                sellerRef: $sellerRef,
                environment: $environment,
                mode: FinancialMode::Administrative,
                operationClass: ModuleOperationClass::NewIntent,
                modules: ['platform', 'ledger', 'documents'],
            ),
            body: function (TransactionContext $context) use ($sellerRef, $environment, $uploadKey, $blobRef, $renderRequestId, $outputDigest, $contentType, $byteSize, $retentionPinnedUntil): DocumentReceipt {
                $this->runner->assertCurrent($context, 'documents');
                $now = self::nowUtc();
                $this->databases->connection($context->connectionName())
                    ->table('billium_document_render_blob')
                    ->insertOrIgnore([
                        'seller_ref' => $sellerRef,
                        'environment' => $environment,
                        'upload_key' => $uploadKey,
                        'blob_ref' => $blobRef,
                        'render_request_id' => $renderRequestId,
                        'output_digest' => $outputDigest,
                        'content_type' => $contentType,
                        'byte_size' => $byteSize,
                        'status' => 'uploaded',
                        'retention_pinned_until' => $retentionPinnedUntil?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                return new DocumentReceipt('documents.render.blob_recorded', ['blobRef' => $blobRef]);
            },
        );
    }

    /** @param array<string, mixed> $diagnostics */
    private function recordRenderFailure(string $sellerRef, string $environment, int $reqId, array $diagnostics): void
    {
        $redacted = self::redactDiagnostics($diagnostics);
        $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'documents.render.record_failure.v1',
                sellerRef: $sellerRef,
                environment: $environment,
                mode: FinancialMode::Administrative,
                operationClass: ModuleOperationClass::NewIntent,
                modules: ['platform', 'ledger', 'documents'],
            ),
            body: function (TransactionContext $context) use ($sellerRef, $environment, $reqId, $redacted): DocumentReceipt {
                $this->runner->assertCurrent($context, 'documents');
                $db = $this->databases->connection($context->connectionName());
                $existing = $db->table('billium_document_render_request')
                    ->where('id', $reqId)
                    ->where('seller_ref', $sellerRef)
                    ->where('environment', $environment)
                    ->first();
                if ($existing !== null && ! in_array(RowCast::str($existing->state, 'state'), [RenderState::Ready->value, RenderState::Superseded->value], true)) {
                    $merged = array_merge(
                        RowCast::jsonObject($existing->redacted_diagnostics, 'redacted_diagnostics'),
                        $redacted,
                    );
                    $db->table('billium_document_render_request')
                        ->where('id', $reqId)
                        ->update([
                            'state' => RenderState::Failed->value,
                            'redacted_diagnostics' => CanonicalJson::encodeObject($merged),
                            'updated_at' => self::nowUtc(),
                        ]);
                }

                return new DocumentReceipt('documents.render.failed', ['id' => $reqId]);
            },
        );
    }

    private function requireRenderById(int $id, string $sellerRef, string $environment): DocumentRenderView
    {
        $row = $this->databases->connection($this->options->connection)->table('billium_document_render_request')
            ->where('id', $id)
            ->where('seller_ref', $sellerRef)
            ->where('environment', $environment)
            ->first();

        if ($row === null) {
            throw new LogicException('Committed render request row not found');
        }

        return self::hydrateRender($row);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function redactDiagnostics(array $values): array
    {
        $out = [];
        foreach ($values as $key => $val) {
            $lower = strtolower($key);
            $sensitive = array_any(self::SENSITIVE_DIAGNOSTIC_KEYS, fn (string $needle): bool => str_contains($lower, $needle));
            if ($sensitive) {
                $out[$key] = '[REDACTED]';

                continue;
            }
            if (is_array($val)) {
                /** @var array<string, mixed> $nested */
                $nested = $val;
                $out[$key] = self::redactDiagnostics($nested);
            } elseif (is_string($val)) {
                $out[$key] = self::redactString($val);
            } elseif (is_int($val) && preg_match('/^\d{13,19}$/', (string) $val) === 1) {
                $out[$key] = '[REDACTED]';
            } else {
                $out[$key] = $val;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $assets
     * @return list<string>
     */
    private static function sanitizeAssetList(array $assets): array
    {
        return array_map(self::redactString(...), $assets);
    }

    private static function redactString(string $value): string
    {
        $value = (string) preg_replace('/Bearer\s+[A-Za-z0-9\-._~+\/=]+/i', 'Bearer [REDACTED]', $value);
        $value = (string) preg_replace_callback(
            '/([?&])([^#&=\s]+)=([^&#\s]*)/',
            static function (array $match): string {
                $full = $match[0];
                $separator = $match[1];
                $rawName = $match[2];
                $decodedName = rawurldecode($rawName);
                $name = strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $decodedName));
                $tokens = implode('|', self::SENSITIVE_DIAGNOSTIC_KEYS);
                $sensitive = preg_match('/(?:^|[^a-z0-9])(?:'.$tokens.')(?:$|[^a-z0-9])/', $name) === 1
                    || in_array($name, ['key', 'auth'], true);

                return $sensitive ? $separator.$rawName.'=[REDACTED]' : $full;
            },
            $value,
        );
        $value = (string) preg_replace('/\b(?:cvv|cvc|pan)\b\s*[:=]\s*\S+/i', '[REDACTED]', $value);

        return (string) preg_replace('/(?<!\d)\d{13,19}(?!\d)/', '[REDACTED]', $value);
    }

    private function assertOutsideTransaction(): void
    {
        foreach ($this->databases->getConnections() as $connection) {
            $writePdo = $connection->getRawPdo();
            $readPdo = $connection->getRawReadPdo();
            if (
                $connection->transactionLevel() !== 0
                || ($writePdo instanceof PDO && $writePdo->inTransaction())
                || ($readPdo instanceof PDO && $readPdo->inTransaction())
            ) {
                throw new LogicException('Document render and storage I/O must run outside every database transaction');
            }
        }
    }

    private static function nowUtc(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
