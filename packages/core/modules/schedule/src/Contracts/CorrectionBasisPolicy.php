<?php

declare(strict_types=1);

namespace Billium\Schedule\Contracts;

use Billium\Schedule\Data\Commands\RegisterCorrectionChild;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Decides which obligation basis a correction child may bind. The module that books the correction owns how that
 * basis is derived; schedule only refuses a child whose basis the installed policy does not recognise.
 */
#[Spi(SpiKind::Implement)]
interface CorrectionBasisPolicy
{
    public function accepts(RegisterCorrectionChild $command): bool;
}
