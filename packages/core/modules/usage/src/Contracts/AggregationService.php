<?php

declare(strict_types=1);

namespace Billium\Usage\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Usage\Data\FreezeAggregate;
use Billium\Usage\Data\FreezeReceipt;

#[Api]
#[Spi(SpiKind::Call)]
interface AggregationService
{
    public function freeze(FreezeAggregate $command): FreezeReceipt;
}
