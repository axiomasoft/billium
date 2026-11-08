<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\LockRequest;
use Billium\Core\Enums\LockRank;
use LogicException;

/**
 * Complete pre-read row set of one allocation-family command in the D27 order: settlement intents (65) → obligations
 * (70) → invoices (80) → payments, then customer credits (90). The guarded routine takes the same rows again, which is
 * reentrant after this prelock; the caller re-reads its plan under the locks and retries on a change (LockPlanChanged).
 */
final readonly class AllocateLockPlan
{
    /** @var list<int> */
    public array $intents;

    /** @var list<int> */
    public array $obligations;

    /** @var list<int> */
    public array $invoices;

    /** @var list<int> */
    public array $payments;

    /** @var list<int> */
    public array $credits;

    /**
     * @param  list<mixed>  $intents
     * @param  list<mixed>  $obligations
     * @param  list<mixed>  $invoices
     * @param  list<mixed>  $payments
     * @param  list<mixed>  $credits
     */
    public function __construct(array $intents = [], array $obligations = [], array $invoices = [], array $payments = [], array $credits = [])
    {
        $this->intents = self::ids($intents);
        $this->obligations = self::ids($obligations);
        $this->invoices = self::ids($invoices);
        $this->payments = self::ids($payments);
        $this->credits = self::ids($credits);
    }

    public function lock(TransactionContext $context): void
    {
        $requests = [];
        foreach ([
            [LockRank::SettlementIntent, 'billium_settlement_intent', $this->intents, 0],
            [LockRank::Obligation, 'billium_obligation', $this->obligations, 0],
            [LockRank::Invoice, 'billium_invoice', $this->invoices, 0],
            [LockRank::Funds, 'billium_payment', $this->payments, LockRank::FUNDS_PAYMENT],
            [LockRank::Funds, 'billium_customer_credit', $this->credits, LockRank::FUNDS_CUSTOMER_CREDIT],
        ] as [$rank, $table, $ids, $segment]) {
            if ($ids !== []) {
                $requests[] = new LockRequest($rank, $table, $ids, segment: $segment, guardedRoutine: 'billium_lock_'.substr($table, strlen('billium_')));
            }
        }
        if ($requests !== []) {
            $context->lock(...$requests);
        }
    }

    /**
     * @param  list<mixed>  $values
     * @return list<int>
     */
    private static function ids(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            $ids[] = is_int($value) ? $value : throw new LogicException('Expected a PostgreSQL bigint id');
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
