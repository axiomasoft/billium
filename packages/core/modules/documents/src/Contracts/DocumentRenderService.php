<?php

declare(strict_types=1);

namespace Billium\Documents\Contracts;

use Billium\Documents\Data\AttachRenderResult;
use Billium\Documents\Data\DocumentRenderView;
use Billium\Documents\Data\DocumentScope;
use Billium\Documents\Data\OrphanCleanupResult;
use Billium\Documents\Data\ReadDocumentRender;
use Billium\Documents\Data\ReadRenderResult;
use Billium\Documents\Data\RenderAttachResult;
use Billium\Documents\Data\RequestDocumentRender;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
interface DocumentRenderService
{
    public function requestRender(RequestDocumentRender $command): DocumentRenderView;

    /** @param list<string> $allowedAssetPrefixes */
    public function executeRender(
        DocumentScope $scope,
        string $renderRequestRef,
        array $allowedAssetPrefixes = ['asset://'],
    ): RenderAttachResult;

    public function attachRenderResult(AttachRenderResult $command): RenderAttachResult;

    public function readRender(ReadDocumentRender $command): ReadRenderResult;

    /**
     * @param  list<string>|null  $allowedActorRefs
     * @return array<string, mixed>
     */
    public function dispatchReadRenderQuery(string $json, ?array $allowedActorRefs = null): array;

    public function cleanupOrphanBlobs(DocumentScope $scope, DateTimeImmutable $asOf): OrphanCleanupResult;
}
