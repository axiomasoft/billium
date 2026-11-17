<?php

declare(strict_types=1);

namespace Billium\Ledger\Contracts;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Ledger\Data\Invoices\IssueCreditNote;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface CorrectionCreditParticipants
{
    /** @param list<IssueCreditNote> $commands */
    public function issue(TransactionContext $context, array $commands): void;
}
