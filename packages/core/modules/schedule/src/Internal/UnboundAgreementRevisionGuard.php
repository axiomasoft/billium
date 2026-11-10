<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal;

use Billium\Core\Contracts\TransactionContext;
use Billium\Schedule\Contracts\AgreementRevisionGuard;
use Billium\Schedule\Data\ScheduleRevisionTerms;

final class UnboundAgreementRevisionGuard implements AgreementRevisionGuard
{
    public function assertCurrent(TransactionContext $context, ScheduleRevisionTerms $terms): void
    {
        ScheduleProblems::refuse('host_agreement_guard_required');
    }
}
