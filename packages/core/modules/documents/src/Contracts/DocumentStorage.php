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

    public function deleteOrphan(string $sellerRef, string $environment, string $blobRef): bool;
}
