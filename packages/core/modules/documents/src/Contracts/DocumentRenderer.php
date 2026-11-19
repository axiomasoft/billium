<?php

declare(strict_types=1);

namespace Billium\Documents\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Host-provided document rendering SPI (full request/result contracts defined in P6.3). */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface DocumentRenderer
{
    public function rendererVersion(): string;
}
