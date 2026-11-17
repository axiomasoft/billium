<?php

declare(strict_types=1);

namespace Billium\Schedule\Contracts;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Schedule\Data\ScheduleRevisionTerms;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Host authority (REQ-NBR-06, D56; owner host). Assert exact approved terms/revision and cancellation generation.
 * The adapter must serialize amendment/cancellation against this root through commit, using transaction-local
 * authority before schedule's rank-40 lock. No network, foreign-table access by Billium or unlocked remote lookup.
 * A missing/tombstoned revision refuses; a stale approval is never replaced with current commercial terms.
 */
#[Spi(SpiKind::Implement)]
interface AgreementRevisionGuard
{
    public function assertCurrent(TransactionContext $context, ScheduleRevisionTerms $terms): void;
}
