<?php

declare(strict_types=1);

namespace Billium\Ledger\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Core\Data\StoredOutcome;
use Billium\Ledger\Data\AdjustObligation;
use Billium\Ledger\Data\DeclareSettlementIntent;
use Billium\Ledger\Data\RecordCustomerCredit;
use Billium\Ledger\Data\RecordObligation;

/** Root-only financial commands (D60). Composition uses LedgerParticipants in the existing root. */
#[Api]
interface Ledger
{
    public function recordObligation(RecordObligation $command): StoredOutcome;

    public function adjustObligation(AdjustObligation $command): StoredOutcome;

    public function recordCustomerCredit(RecordCustomerCredit $command): StoredOutcome;

    public function declareSettlementIntent(DeclareSettlementIntent $command): StoredOutcome;
}
