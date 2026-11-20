<?php

declare(strict_types=1);

namespace Billium\Collection\Contracts;

use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\ConsentBindingView;
use Billium\Collection\Data\ConsentChargeEligibilityRequest;
use Billium\Collection\Data\ConsentEligibilityVerdict;
use Billium\Collection\Data\ConsentGuardStateView;
use Billium\Collection\Data\CustomerActionReceipt;
use Billium\Collection\Data\OriginalRefundRouteView;
use Billium\Collection\Data\RecordCustomerAction;
use Billium\Collection\Data\RecordOriginalRefundRoute;
use Billium\Collection\Data\RegisterConsentBinding;
use Billium\Collection\Data\RevocationReceipt;
use Billium\Collection\Data\RevokeConsentCommand;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\MerchantRevision;
use Packman\Attributes\Api;

/**
 * Owns versioned ConsentBinding proofs, durable DunningCase-rank consent/cancellation guards,
 * immutable original refund routes, and requires_action customer-action receipts.
 */
#[Api(since: '1.0')]
interface ConsentBindingService
{
    public function registerBinding(
        RegisterConsentBinding $command,
        MerchantRevision $merchant,
        CapabilitySnapshot $capabilities,
    ): ConsentBindingView;

    public function revokeOrCancel(RevokeConsentCommand $command): RevocationReceipt;

    /**
     * Queries the host CollectionConsentGuard outside every DB transaction and, when inactive,
     * persists durable local revocation/tombstone state whose receipt defines local effectiveness.
     */
    public function syncHostAuthority(
        CollectionScope $scope,
        string $billingAccountRef,
        string $consentRef,
        string $commandKey,
        string $tombstoneRef,
    ): ?RevocationReceipt;

    public function evaluateChargeEligibility(
        ConsentChargeEligibilityRequest $request,
        ?TransactionContext $context = null,
    ): ConsentEligibilityVerdict;

    public function lockGuard(
        TransactionContext $context,
        CollectionScope $scope,
        string $consentRef,
    ): ConsentGuardStateView;

    public function recordOriginalRefundRoute(RecordOriginalRefundRoute $command): OriginalRefundRouteView;

    public function assertRefundRouteMatchesOriginal(
        CollectionScope $scope,
        string $operationRef,
        string $merchantAccountRef,
        string $merchantConfigRevision,
    ): OriginalRefundRouteView;

    public function recordCustomerAction(RecordCustomerAction $command): CustomerActionReceipt;
}
