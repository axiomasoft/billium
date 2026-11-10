<?php

declare(strict_types=1);

namespace Billium\Ledger\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Core\Contracts\TransactionContext;
use Billium\Ledger\Data\IssueCreditNote;

#[Api]
#[Spi(SpiKind::Call)]
interface CorrectionCreditParticipants
{
    /** @param list<IssueCreditNote> $commands */
    public function issue(TransactionContext $context, array $commands): void;
}
