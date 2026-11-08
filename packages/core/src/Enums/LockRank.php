<?php

declare(strict_types=1);

namespace Billium\Core\Enums;

use Billium\Contracts\Attributes\Api;

/**
 * The single global lock order (D27, invariant-model). A root transaction acquires ranks strictly ascending; inside a
 * rank, ids only ascend. Claim is the first write of the root Tx (an insert, not a row lock). Funds orders payment ids
 * (segment 0) before customer-credit ids (segment 1).
 */
#[Api]
enum LockRank: int
{
    case Claim = 10;
    case FinancialSession = 15;
    case InstallationState = 20;
    case ModuleAdmission = 25;
    case FeedPartition = 30;
    case BillingSchedule = 40;
    case BillingOccurrence = 50;
    case DunningCase = 60;
    case SettlementIntent = 65;
    case Obligation = 70;
    case Invoice = 80;
    case Funds = 90;
    case RootCharge = 100;
    case ChildEffect = 110;
    case Capacity = 115;
    case Attempt = 120;
    case DocumentSeries = 130;

    public const int FUNDS_PAYMENT = 0;

    public const int FUNDS_CUSTOMER_CREDIT = 1;
}
