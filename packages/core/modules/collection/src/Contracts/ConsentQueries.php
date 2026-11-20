<?php

declare(strict_types=1);

namespace Billium\Collection\Contracts;

use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\ConsentBindingView;
use Billium\Collection\Data\ConsentGuardStateView;
use Billium\Collection\Data\CustomerActionReceipt;
use Billium\Collection\Data\OriginalRefundRouteView;
use Billium\Collection\Data\RevocationReceipt;
use Packman\Attributes\Api;

/**
 * Authenticated, seller-scoped read queries for consent proofs, durable guard state, tombstones,
 * customer-action receipts, and historical refund routes.
 */
#[Api(since: '1.0')]
interface ConsentQueries
{
    public function findBinding(CollectionScope $scope, string $consentRef, int $consentVersion): ?ConsentBindingView;

    public function findGuardState(CollectionScope $scope, string $consentRef): ?ConsentGuardStateView;

    /** @return list<RevocationReceipt> */
    public function listTombstones(CollectionScope $scope, string $consentRef): array;

    public function findCustomerAction(CollectionScope $scope, string $actionRef): ?CustomerActionReceipt;

    public function findOriginalRefundRoute(CollectionScope $scope, string $operationRef): ?OriginalRefundRouteView;
}
