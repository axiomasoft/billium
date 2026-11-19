<?php

declare(strict_types=1);

namespace Billium\Documents\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Optional host delivery channel declaration for rendered legal documents. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface DocumentDelivery
{
    public function deliveryChannel(): string;
}
