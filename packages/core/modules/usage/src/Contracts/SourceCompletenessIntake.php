<?php

declare(strict_types=1);

namespace Billium\Usage\Contracts;

use Billium\Usage\Data\Commands\RecordSourceCompleteness;
use Billium\Usage\Data\FreezeReceipt;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(SpiKind::Call)]
interface SourceCompletenessIntake
{
    public function record(RecordSourceCompleteness $command): FreezeReceipt;
}
