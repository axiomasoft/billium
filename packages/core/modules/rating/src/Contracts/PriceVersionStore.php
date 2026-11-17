<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Rating\Data\Pricing\PriceVersion;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(SpiKind::Call)]
interface PriceVersionStore
{
    /** @return array<string, mixed> */
    public function publish(PriceVersion $price, string $commandKey): array;

    public function find(string $priceRef, int $version): ?PriceVersion;
}
