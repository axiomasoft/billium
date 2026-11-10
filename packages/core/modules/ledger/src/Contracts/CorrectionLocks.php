<?php

declare(strict_types=1);

namespace Billium\Ledger\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Core\Contracts\TransactionContext;

#[Api]
#[Spi(SpiKind::Call)]
interface CorrectionLocks
{
    public function acquireParent(TransactionContext $context, string $obligationRef, string $accountRef): void;
}
