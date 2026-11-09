<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Observation;

use Billium\Core\Contracts\CallerContext;
use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\OperationSpec;
use Billium\Core\Data\Receipt;
use Billium\Core\Enums\FinancialMode;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Ledger\Data\LedgerReceipt;
use Billium\Platform\Contracts\CustodyHandler;
use Billium\Platform\Contracts\FeedPartitions;
use Billium\Platform\Data\Inbox\CustodyDisposition;
use Billium\Platform\Data\Inbox\CustodyResult;
use Billium\Platform\Data\Inbox\HandlerOutcomeKind;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use LogicException;

/**
 * Drives observation work one economic event per NEW root Tx (inbox-feed): the next accounted sequence of a partition,
 * the later parked revision of an operation that was just resolved, and parked observations whose dependency (settlement
 * intent/account or original charge) now exists. Nothing here loops over a second event inside one root, so no
 * lower-rank lock is ever discovered after funds; a dependency-parked observation never blocks its partition.
 */
final readonly class ParkedDrainer
{
    public const string CHANNEL = 'payment.observation';

    /** Upper bound of follow-up roots after one step; a source cannot pin a worker forever. */
    private const int MAX_FOLLOW = 10_000;

    public function __construct(
        private OperationRunner $runner,
        private CallerContext $callers,
        private FeedPartitions $feed,
        private ObservationHandler $handler,
        private PaymentProjection $projection,
        private DatabaseManager $databases,
    ) {}

    /**
     * One root operation of the observation path; the body result is returned after commit.
     *
     * @template T
     *
     * @param  Closure(TransactionContext): T  $body
     * @return T
     */
    public function root(Closure $body, string $kind = 'ledger.observation_apply', FinancialMode $mode = FinancialMode::Active, ?string $commitKind = 'ledger.observation'): mixed
    {
        $caller = $this->callers->current();
        $spec = new OperationSpec($kind, $caller->sellerRef, $caller->environment, $mode, ModuleOperationClass::ObservedFactRecording, ['ledger', 'platform'],
            null, null, $commitKind);
        $captured = null;
        $done = false;
        $this->runner->run($spec, static function (TransactionContext $context) use ($body, &$captured, &$done): Receipt {
            $captured = $body($context);
            $done = true;

            return new LedgerReceipt('ledger.observation_root', ['kind' => 'observation']);
        });
        if (! $done) {
            throw new LogicException('The observation root did not run its body');
        }

        /** @var T $captured */
        return $captured;
    }

    /**
     * Raw custody of one authenticated delivery (D60): a RawCustody root takes no financial session barrier and no
     * monetary commit kind, so custody commits even while financial application is sealed. It never invokes a handler.
     */
    public function custody(\stdClass $envelope, CustodyHandler $handler): CustodyResult
    {
        $caller = $this->callers->current();
        $spec = new OperationSpec('platform.inbox_custody', $caller->sellerRef, $caller->environment, FinancialMode::RawCustody,
            ModuleOperationClass::CallbackCustody, ['platform']);
        $captured = null;
        $this->runner->run($spec, function (TransactionContext $context) use ($envelope, $handler, &$captured): Receipt {
            $captured = $this->feed->process($context, self::CHANNEL, $envelope, $handler);

            return new LedgerReceipt('ledger.observation_custody', ['kind' => 'custody']);
        });

        return $captured instanceof CustodyResult ? $captured : throw new LogicException('The custody root did not run its body');
    }

    /** The configured Billium connection (billium.connection), never the host's default database, for work selection. */
    public function connection(): Connection
    {
        $name = config('billium.connection');

        return $this->databases->connection(is_string($name) ? $name : null);
    }

    /**
     * Follows a committed custody step: drains the next sequence and retries the later parked revision it names, each
     * in its own root of the given financial mode, until nothing is next.
     *
     * @return list<CustodyResult>
     */
    public function follow(CustodyResult $result, ?CustodyHandler $handler = null, FinancialMode $mode = FinancialMode::Active): array
    {
        $handler ??= $this->handler;
        $results = [];
        $pending = [$result];
        for ($steps = 0; $pending !== [] && $steps < self::MAX_FOLLOW; $steps++) {
            $current = array_shift($pending);
            $next = [];
            if ($current->drainPartitionId !== null) {
                $partition = $current->drainPartitionId;
                $next[] = $this->root(fn (TransactionContext $context): CustodyResult => $this->feed->drain($context, $partition, $handler), mode: $mode);
            }
            if ($current->retryInboxId !== null && $current->retryInboxId !== $current->inboxId) {
                $inbox = $current->retryInboxId;
                $next[] = $this->root(fn (TransactionContext $context): CustodyResult => $this->retry($context, $inbox, $handler), mode: $mode);
            }
            foreach ($next as $step) {
                if ($step->disposition !== CustodyDisposition::Idle) {
                    $results[] = $step;
                    $pending[] = $step;
                }
            }
        }

        return $results;
    }

    /** @return list<CustodyResult> every step until the partition has nothing next */
    public function drainPartition(int $partitionId, FinancialMode $mode = FinancialMode::Active): array
    {
        return $this->follow(new CustodyResult(CustodyDisposition::Idle, drainPartitionId: $partitionId), mode: $mode);
    }

    /**
     * Retries parked observations whose dependency now exists, oldest first, one root each (plus what they unblock).
     *
     * @return list<string> the resulting inbox/item status of every retried observation
     */
    public function retryParked(int $limit = 100, FinancialMode $mode = FinancialMode::Active): array
    {
        $caller = $this->callers->current();
        $statuses = [];
        $attempted = [];
        while (count($statuses) < $limit) {
            // Re-select after each root so releasing an earlier bootstrap park admits its waiting revision.
            $row = $this->connection()->selectOne(<<<'SQL'
            SELECT k.id, k.inbox_id FROM public.billium_observation_park k
            WHERE k.seller_ref = ? AND k.environment = ? AND k.released_at IS NULL
              AND NOT (k.id = ANY(?::bigint[])) AND (
                (k.dependency_kind = 'settlement_intent' AND EXISTS (
                    SELECT 1 FROM public.billium_settlement_intent i
                    WHERE i.seller_ref = k.seller_ref AND i.environment = k.environment AND i.business_intent_ref = k.dependency_ref))
                OR (k.dependency_kind = 'original_charge' AND EXISTS (
                    SELECT 1 FROM public.billium_payment p JOIN public.billium_observed_payment o ON o.id = k.observed_payment_id
                    WHERE p.seller_ref = k.seller_ref AND p.environment = k.environment AND p.payment_ref = k.dependency_ref AND p.kind = 'charge'
                      AND o.seller_ref = k.seller_ref AND o.environment = k.environment AND p.source_authority = o.source_authority))
                OR (k.dependency_kind = 'operation_revision' AND NOT EXISTS (
                    SELECT 1 FROM public.billium_observation_park k2
                    WHERE k2.observed_payment_id = k.observed_payment_id AND k2.released_at IS NULL AND k2.revision < k.revision)))
            ORDER BY k.revision, k.id LIMIT 1
            SQL, [$caller->sellerRef, $caller->environment, '{'.implode(',', $attempted).'}']);
            if ($row === null) {
                break;
            }
            $park = is_object($row) && is_int($row->id ?? null) ? $row->id : throw new LogicException('Expected a park id');
            $attempted[] = $park;
            $inbox = is_int($row->inbox_id ?? null) ? $row->inbox_id : null;
            if ($inbox !== null) {
                $result = $this->root(fn (TransactionContext $context): CustodyResult => $this->retry($context, $inbox, $this->handler), mode: $mode);
                $statuses[] = $result->status ?? $result->disposition->value;
                $this->follow($result, mode: $mode);
            } else {
                $statuses[] = $this->root(fn (TransactionContext $context): string => $this->reproject($context, $park), mode: $mode);
            }
        }

        return $statuses;
    }

    /** The platform retry of one parked delivery; a delivery no longer parked releases its ledger marker. */
    private function retry(TransactionContext $context, int $inboxId, CustodyHandler $handler): CustodyResult
    {
        $result = $this->feed->retryDependency($context, $inboxId, $handler);
        if ($result->status !== null && $result->status !== 'parked_dependency') {
            $this->projection->release($this->databases->connection($context->connectionName()), $context->caller(), null, $inboxId, $result->status);
        }

        return $result;
    }

    /** A parked bootstrap item: re-project the stored observed revision (keyed by operation/revision, own root). */
    private function reproject(TransactionContext $context, int $parkId): string
    {
        $db = $this->databases->connection($context->connectionName());
        $caller = $context->caller();
        $row = $db->table('billium_observation_park as k')
            ->join('billium_observed_payment_revision as r', static function (JoinClause $join): void {
                $join->on('r.seller_ref', '=', 'k.seller_ref')->on('r.environment', '=', 'k.environment')
                    ->on('r.observed_payment_id', '=', 'k.observed_payment_id')->on('r.revision', '=', 'k.revision');
            })
            ->where('k.id', $parkId)->where('k.seller_ref', $caller->sellerRef)->where('k.environment', $caller->environment)->whereNull('k.released_at')
            ->first(['r.payload']);
        if ($row === null) {
            return 'released';
        }
        $payload = json_decode(is_string($row->payload) ? $row->payload : '', false, 64, JSON_THROW_ON_ERROR);
        $observation = $payload instanceof \stdClass ? ObservationHandler::decode($payload) : null;
        if ($observation === null) {
            $this->projection->release($db, $caller, $parkId, null, 'quarantined');

            return 'quarantined';
        }
        $outcome = $this->projection->apply($context, $observation);
        if ($outcome->kind === HandlerOutcomeKind::ParkedDependency) {
            return 'parked_dependency';
        }
        $status = $outcome->kind === HandlerOutcomeKind::Applied ? 'applied' : 'quarantined';
        $this->projection->release($db, $caller, $parkId, null, $status);

        return $status;
    }
}
