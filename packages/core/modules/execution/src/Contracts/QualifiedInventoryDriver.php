<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\QualifiedInventoryPage;
use Billium\Execution\Data\ResolvedCredentials;
use DateTimeImmutable;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Optional driver SPI for authenticated, immutable provider statements. Called outside every transaction. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface QualifiedInventoryDriver
{
    public function inventoryWindow(MerchantRevision $merchant, ResolvedCredentials $credentials, string $currency, string $sourceAuthority,
        DateTimeImmutable $from, DateTimeImmutable $through, ?string $cursor): QualifiedInventoryPage;
}
