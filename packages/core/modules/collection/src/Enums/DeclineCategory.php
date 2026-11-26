<?php

declare(strict_types=1);

namespace Billium\Collection\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum DeclineCategory: string
{
    case InsufficientFunds = 'insufficient_funds';
    case TemporaryNetwork = 'temporary_network';
    case IssuerUnavailable = 'issuer_unavailable';
    case SoftDecline = 'soft_decline';
    case CardExpired = 'card_expired';
    case DoNotHonor = 'do_not_honor';
    case FraudSuspected = 'fraud_suspected';
    case InvalidAccount = 'invalid_account';
    case RevokedMandate = 'revoked_mandate';
    case HardDecline = 'hard_decline';
}
