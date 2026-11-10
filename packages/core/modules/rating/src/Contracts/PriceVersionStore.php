<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Rating\Data\Pricing\PriceVersion;

#[Api]
#[Spi(SpiKind::Call)]
interface PriceVersionStore
{
    /** @return array<string, mixed> */
    public function publish(PriceVersion $price, string $commandKey): array;

    public function find(string $priceRef, int $version): ?PriceVersion;
}
