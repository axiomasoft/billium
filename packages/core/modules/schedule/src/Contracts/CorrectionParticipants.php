<?php

declare(strict_types=1);

namespace Billium\Schedule\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Core\Contracts\TransactionContext;
use Billium\Schedule\Data\Commands\RegisterCorrectionChild;
use Billium\Schedule\Data\CorrectionReceipt;

#[Api]
#[Spi(SpiKind::Call)]
interface CorrectionParticipants
{
    public function registerCorrectionChild(TransactionContext $context, RegisterCorrectionChild $command): CorrectionReceipt;
}
