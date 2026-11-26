<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Queries;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Ledger\Contracts\SettlementIntentLocks;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Illuminate\Database\Connection;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseSettlementIntentLocks implements SettlementIntentLocks
{
    public function __construct(private ReadSession $session) {}

    public function plan(BillingAccountScope $scope, string $businessIntentRef): LockRequest
    {
        return $this->session->run(static function (Connection $db, Caller $caller) use ($scope, $businessIntentRef): LockRequest {
            if ($scope->sellerRef !== $caller->sellerRef || $scope->environment !== $caller->environment || $businessIntentRef === '') {
                throw new ProblemException(ProblemCode::ReferenceUnknown, 'Unknown settlement intent in this scope');
            }
            $key = $db->table('billium_settlement_intent')->where('installation_ref', $caller->installationRef)->where('seller_ref', $scope->sellerRef)
                ->where('environment', $scope->environment)->where('billing_account_ref', $scope->billingAccountRef)->where('business_intent_ref', $businessIntentRef)->value('id');
            if (! is_int($key) && (! is_string($key) || ! ctype_digit($key))) {
                throw new ProblemException(ProblemCode::ReferenceUnknown, 'Unknown settlement intent in this scope');
            }

            return new LockRequest(LockRank::SettlementIntent, 'billium_settlement_intent', [(int) $key], guardedRoutine: 'billium_lock_settlement_intent');
        });
    }
}
