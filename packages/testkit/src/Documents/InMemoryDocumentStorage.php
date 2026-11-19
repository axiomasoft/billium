<?php

declare(strict_types=1);

namespace Billium\Testkit\Documents;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Documents\Contracts\DocumentStorage;
use Billium\Documents\Data\ReadBlobGrantRequest;
use Billium\Documents\Data\StoredBlob;
use Billium\Documents\Data\StoreRenderBlob;
use Billium\Documents\Data\TemporaryBlobGrant;
use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Api;
use RuntimeException;

/**
 * Deterministic in-memory DocumentStorage test double supporting idempotent upload keys,
 * simulated timeout-after-upload, ephemeral signed URL grants, and owner orphan deletion.
 */
#[Api(since: '1.0')]
final class InMemoryDocumentStorage implements DocumentStorage
{
    /** @var array<string, array{blobRef: string, outputDigest: string, contentType: string, bytes: string, sellerRef: string, environment: string, deleted: bool}> */
    private array $byUploadKey = [];

    /** @var array<string, string> blobRef => uploadKey */
    private array $uploadKeyByBlobRef = [];

    /** @var array<string, array{sellerRef: string, environment: string, blobRef: string, actorRef: string, expiresAt: DateTimeImmutable}> */
    private array $issuedUrls = [];

    public int $storeAttempts = 0;

    public bool $failAfterStoreNext = false;

    public DateTimeImmutable $clock;

    public function __construct(?DateTimeImmutable $clock = null)
    {
        $this->clock = $clock ?? new DateTimeImmutable('2026-11-19T12:00:00Z', new DateTimeZone('UTC'));
    }

    public function store(StoreRenderBlob $command): StoredBlob
    {
        $this->storeAttempts++;
        $scopedKey = $command->sellerRef.'|'.$command->environment.'|'.$command->uploadKey;

        if (isset($this->byUploadKey[$scopedKey])) {
            $existing = $this->byUploadKey[$scopedKey];
            if ($this->failAfterStoreNext) {
                $this->failAfterStoreNext = false;

                throw new RuntimeException('simulated storage timeout after upload');
            }

            return new StoredBlob(
                blobRef: $existing['blobRef'],
                outputDigest: $existing['outputDigest'],
                contentType: $existing['contentType'],
                byteSize: strlen($existing['bytes']),
                replayed: true,
            );
        }

        $blobRef = 'blob-'.substr(hash('sha256', $scopedKey.'|'.$command->outputDigest), 0, 24);
        $this->byUploadKey[$scopedKey] = [
            'blobRef' => $blobRef,
            'outputDigest' => $command->outputDigest,
            'contentType' => $command->contentType,
            'bytes' => $command->bytes,
            'sellerRef' => $command->sellerRef,
            'environment' => $command->environment,
            'deleted' => false,
        ];
        $this->uploadKeyByBlobRef[$blobRef] = $scopedKey;

        if ($this->failAfterStoreNext) {
            $this->failAfterStoreNext = false;

            throw new RuntimeException('simulated storage timeout after upload');
        }

        return new StoredBlob(
            blobRef: $blobRef,
            outputDigest: $command->outputDigest,
            contentType: $command->contentType,
            byteSize: strlen($command->bytes),
            replayed: false,
        );
    }

    public function issueReadGrant(ReadBlobGrantRequest $request): TemporaryBlobGrant
    {
        $key = $this->uploadKeyByBlobRef[$request->blobRef] ?? null;
        if ($key === null || $this->byUploadKey[$key]['deleted']) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'blob not found in storage');
        }
        $record = $this->byUploadKey[$key];
        if ($record['sellerRef'] !== $request->sellerRef || $record['environment'] !== $request->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'storage seller mismatch');
        }

        $expiresAt = $this->clock->modify('+'.$request->ttlSeconds.' seconds');
        $sig = substr(hash('sha256', $request->blobRef.'|'.$request->actorRef.'|'.$expiresAt->format('U.u')), 0, 16);
        $url = 'https://storage.local/read/'.$request->blobRef.'?exp='.$expiresAt->getTimestamp().'&sig='.$sig;
        $this->issuedUrls[$url] = [
            'sellerRef' => $request->sellerRef,
            'environment' => $request->environment,
            'blobRef' => $request->blobRef,
            'actorRef' => $request->actorRef,
            'expiresAt' => $expiresAt,
        ];

        return new TemporaryBlobGrant(
            blobRef: $request->blobRef,
            temporaryUrl: $url,
            expiresAt: $expiresAt,
        );
    }

    public function deleteOrphan(string $sellerRef, string $environment, string $blobRef): bool
    {
        $key = $this->uploadKeyByBlobRef[$blobRef] ?? null;
        if ($key === null) {
            return true;
        }
        $record = $this->byUploadKey[$key];
        if ($record['sellerRef'] !== $sellerRef || $record['environment'] !== $environment) {
            return false;
        }
        $this->byUploadKey[$key]['deleted'] = true;

        return true;
    }

    public function fetchByTemporaryUrl(string $url, DateTimeImmutable $at): ?string
    {
        $grant = $this->issuedUrls[$url] ?? null;
        if ($grant === null || $at >= $grant['expiresAt']) {
            return null;
        }
        $key = $this->uploadKeyByBlobRef[$grant['blobRef']] ?? null;
        if ($key === null || $this->byUploadKey[$key]['deleted']) {
            return null;
        }

        return $this->byUploadKey[$key]['bytes'];
    }

    public function activeBlobCount(): int
    {
        $count = 0;
        foreach ($this->byUploadKey as $entry) {
            if (! $entry['deleted']) {
                $count++;
            }
        }

        return $count;
    }

    public function isBlobAlive(string $blobRef): bool
    {
        $key = $this->uploadKeyByBlobRef[$blobRef] ?? null;

        return $key !== null && ! $this->byUploadKey[$key]['deleted'];
    }
}
