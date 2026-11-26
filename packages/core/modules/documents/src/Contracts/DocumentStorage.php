<?php

declare(strict_types=1);

namespace Billium\Documents\Contracts;

use Billium\Documents\Data\ReadBlobGrantRequest;
use Billium\Documents\Data\StoredBlob;
use Billium\Documents\Data\StoreRenderBlob;
use Billium\Documents\Data\TemporaryBlobGrant;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Host-provided idempotent blob storage SPI with ephemeral read grants and owner orphan cleanup. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface DocumentStorage
{
    public function store(StoreRenderBlob $command): StoredBlob;

    public function issueReadGrant(ReadBlobGrantRequest $request): TemporaryBlobGrant;

    /**
     * Deletes an orphaned render blob in the given (sellerRef, environment) scope.
     *
     * Implementations MUST be idempotent: return true both when the object is removed and when
     * the object is already absent in the requested scope (allowing safe crash recovery after
     * storage deletion succeeded before database finalization committed). Return false (or throw)
     * on transient storage failures or cross-seller/environment scope mismatches.
     */
    public function deleteOrphan(string $sellerRef, string $environment, string $blobRef): bool;
}
