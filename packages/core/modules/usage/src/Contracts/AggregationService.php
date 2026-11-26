<?php

declare(strict_types=1);

namespace Billium\Usage\Contracts;

use Billium\Usage\Data\FreezeAggregate;
use Billium\Usage\Data\FreezeReceipt;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(SpiKind::Call)]
interface AggregationService
{
    public function freeze(FreezeAggregate $command): FreezeReceipt;
}
