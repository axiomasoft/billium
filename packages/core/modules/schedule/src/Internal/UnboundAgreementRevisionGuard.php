<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Schedule\Contracts\AgreementRevisionGuard;
use Billium\Schedule\Data\ScheduleRevisionTerms;
use Packman\Attributes\Internal;

#[Internal]
final class UnboundAgreementRevisionGuard implements AgreementRevisionGuard
{
    public function assertCurrent(TransactionContext $context, ScheduleRevisionTerms $terms): void
    {
        ScheduleProblems::refuse('host_agreement_guard_required');
    }
}
