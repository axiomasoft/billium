<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Queries;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Ledger\Contracts\CollectionObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class DatabaseCollectionObligation implements CollectionObligation
{
    public function __construct(private DatabaseManager $databases, private OperationRunner $runner) {}

    public function lockCollectibleMinor(TransactionContext $context, BillingAccountScope $scope, string $obligationRef, string $intentRef, string $currency, string $customerRef, ?string $componentRef, ?DateTimeImmutable $dueAsOf = null): int
    {
        [$db, $row, $id] = $this->lockObligation($context, $scope, $obligationRef, $intentRef, $currency, $customerRef, $componentRef);
        $eligibleAt = $row->collection_eligible_at;
        $asOf = $dueAsOf ?? new DateTimeImmutable;
        if ($row->status !== 'open' || $row->open_minor <= 0
            || ($eligibleAt !== null && (! is_string($eligibleAt) || new DateTimeImmutable($eligibleAt) > $asOf))
            || ($dueAsOf !== null && (! is_string($row->due_at) || new DateTimeImmutable($row->due_at) > $dueAsOf
                || $row->due_policy_version === null))) {
            return 0;
        }

        // A refund reversal may leave an accounting balance, but cannot create automatically collectible debt.
        $seller = is_string($row->seller_ref) ? $row->seller_ref : throw new LockPlanChanged('Collection obligation vanished');
        $environment = is_string($row->environment) ? $row->environment : throw new LockPlanChanged('Collection obligation vanished');
        $minor = $db->scalar(<<<'SQL'
            SELECT greatest(0, o.open_minor::numeric - greatest(0,
                -coalesce((SELECT sum(a.amount_minor) FROM billium_allocation a
                    WHERE a.seller_ref = ? AND a.environment = ? AND a.obligation_id = o.id AND a.cause = 'refund'), 0)
                -coalesce((SELECT sum(n.amount_minor) FROM billium_credit_note n
                    WHERE n.seller_ref = ? AND n.environment = ? AND n.obligation_id = o.id AND n.reason = 'refund'
                    AND EXISTS (SELECT 1 FROM billium_allocation a
                        WHERE a.seller_ref = ? AND a.environment = ? AND a.obligation_id = o.id
                          AND a.cause = 'refund' AND a.reverses_id IS NOT NULL AND a.receipt_ref = n.receipt_ref)), 0)
            ))::bigint FROM billium_obligation o WHERE o.id = ?
            SQL, [$seller, $environment, $seller, $environment, $seller, $environment, $id]);

        return is_int($minor) ? $minor : throw new LockPlanChanged('Collection obligation vanished');
    }

    public function lockOutstandingMinor(TransactionContext $context, BillingAccountScope $scope, string $obligationRef, string $intentRef, string $currency, string $customerRef): int
    {
        [, $row] = $this->lockObligation($context, $scope, $obligationRef, $intentRef, $currency, $customerRef, null);

        return is_int($row->open_minor) ? $row->open_minor : throw new LockPlanChanged('Collection outstanding amount is unavailable');
    }

    public function obligationCurrency(TransactionContext $context, BillingAccountScope $scope, string $obligationRef): ?string
    {
        $this->runner->assertCurrent($context, 'ledger');
        $caller = $context->caller();
        if ($scope->sellerRef !== $caller->sellerRef || $scope->environment !== $caller->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Collection obligation is outside the admitted scope');
        }
        $currency = $this->databases->connection($context->connectionName())->table('billium_obligation')
            ->where(['seller_ref' => $scope->sellerRef, 'environment' => $scope->environment, 'installation_ref' => $caller->installationRef,
                'billing_account_ref' => $scope->billingAccountRef, 'obligation_ref' => $obligationRef])->value('currency');

        return is_string($currency) ? $currency : null;
    }

    /** @return array{Connection, stdClass, int} */
    private function lockObligation(TransactionContext $context, BillingAccountScope $scope, string $obligationRef, string $intentRef, string $currency, string $customerRef, ?string $componentRef): array
    {
        $this->runner->assertCurrent($context, 'ledger');
        $caller = $context->caller();
        if ($scope->sellerRef !== $caller->sellerRef || $scope->environment !== $caller->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Collection obligation is outside the admitted scope');
        }
        $db = $this->databases->connection($context->connectionName());
        $address = ['seller_ref' => $scope->sellerRef, 'environment' => $scope->environment];
        $target = $db->table('billium_obligation')->where($address)->where('installation_ref', $caller->installationRef)
            ->where('billing_account_ref', $scope->billingAccountRef)->where('obligation_ref', $obligationRef);
        $id = $target->value('id');
        if (! is_int($id)) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'Unknown collection obligation');
        }
        $intentId = $db->table('billium_settlement_intent')->where($address)->where('business_intent_ref', $intentRef)->value('id');
        $locks = [new LockRequest(LockRank::Obligation, 'billium_obligation', [$id], guardedRoutine: 'billium_lock_obligation')];
        if (is_int($intentId)) {
            $locks[] = new LockRequest(LockRank::SettlementIntent, 'billium_settlement_intent', [$intentId], guardedRoutine: 'billium_lock_settlement_intent');
        }
        $context->lock(...$locks);
        $row = $target->first() ?? throw new LockPlanChanged('Collection obligation vanished');
        if ($row->id !== $id || $db->table('billium_settlement_intent')->where($address)->where('business_intent_ref', $intentRef)->value('id') !== $intentId) {
            throw new LockPlanChanged('Collection obligation or settlement identity changed');
        }
        if ($row->currency !== $currency || $row->customer_ref !== $customerRef || ($componentRef !== null && $row->component_ref !== $componentRef)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Collection obligation differs from the frozen consent scope');
        }

        return [$db, $row, $id];
    }

    public function outcomeApplied(TransactionContext $context, BillingAccountScope $scope, string $intentRef, string $operationRef, string $authority, int $revision, string $state): bool
    {
        $this->runner->assertCurrent($context, 'ledger');
        $caller = $context->caller();
        if ($scope->sellerRef !== $caller->sellerRef || $scope->environment !== $caller->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Collection outcome is outside the admitted scope');
        }
        $db = $this->databases->connection($context->connectionName());

        return $db->table('billium_payment as p')
            ->join('billium_settlement_intent as i', 'i.id', '=', 'p.settlement_intent_id')
            ->join('billium_observed_payment as o', static function (JoinClause $join): void {
                $join->on('o.seller_ref', '=', 'p.seller_ref')->on('o.environment', '=', 'p.environment')
                    ->on('o.source_authority', '=', 'p.source_authority')->on('o.source_lineage', '=', 'p.source_lineage')
                    ->on('o.operation_ref', '=', 'p.operation_ref');
            })
            ->where('p.installation_ref', $caller->installationRef)->where('p.seller_ref', $scope->sellerRef)
            ->where('p.environment', $scope->environment)->where('p.billing_account_ref', $scope->billingAccountRef)
            ->where('i.business_intent_ref', $intentRef)->where('p.operation_ref', $operationRef)
            ->where('p.source_authority', $authority)->where('p.applied_revision', $revision)
            ->where('o.revision', $revision)->where('o.state', $state)
            ->where('p.financial_hold', false)->where('p.reallocation_required', false)->exists();
    }
}
