<?php

declare(strict_types=1);

namespace Billium\Schedule\Contracts;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Schedule\Data\Commands\RegisterCorrectionChild;
use Billium\Schedule\Data\CorrectionReceipt;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(SpiKind::Call)]
interface CorrectionParticipants
{
    public function registerCorrectionChild(TransactionContext $context, RegisterCorrectionChild $command): CorrectionReceipt;
}
