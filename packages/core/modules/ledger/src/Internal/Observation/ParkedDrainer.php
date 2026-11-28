<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Observation;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Ledger\Data\Runtime\LedgerReceipt;
use Billium\Platform\Contracts\Feed\FeedPartitions;
use Billium\Platform\Contracts\Inbox\CustodyHandler;
use Billium\Platform\Data\Inbox\CustodyDisposition;
use Billium\Platform\Data\Inbox\CustodyResult;
use Billium\Platform\Data\Inbox\HandlerOutcomeKind;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

/**
 * Drives observation work one economic event per NEW root Tx (inbox-feed): the next accounted sequence of a partition,
 * the later parked revision of an operation that was just resolved, and parked observations whose dependency (settlement
 * intent/account or original charge) now exists. Nothing here loops over a second event inside one root, so no
 * lower-rank lock is ever discovered after funds; a dependency-parked observation never blocks its partition.
 */
#[Internal]
final readonly class ParkedDrainer
{
    public const string CHANNEL = 'payment.observation';

    /** Upper bound of follow-up roots after one step; a source cannot pin a worker forever. */
    private const int MAX_FOLLOW = 10_000;

    /** Shared readiness predicate for a park k and its observed payment o. */
    private const string DEPENDENCY_READY = <<<'SQL'
        (k.dependency_kind = 'settlement_intent' AND EXISTS (
            SELECT 1 FROM public.billium_settlement_intent i
            WHERE i.seller_ref = k.seller_ref AND i.environment = k.environment AND i.business_intent_ref = k.dependency_ref))
        OR (k.dependency_kind = 'original_charge' AND EXISTS (
            SELECT 1 FROM public.billium_payment p
            WHERE p.seller_ref = k.seller_ref AND p.environment = k.environment AND p.payment_ref = k.dependency_ref AND p.kind = 'charge'
              AND p.source_authority = o.source_authority))
        OR (k.dependency_kind = 'operation_revision' AND NOT EXISTS (
            SELECT 1 FROM public.billium_observation_park k2
            WHERE k2.observed_payment_id = k.observed_payment_id AND k2.released_at IS NULL AND k2.revision < k.revision))
        SQL;

    public function __construct(
        private OperationRunner $runner,
        private CallerContext $callers,
        private FeedPartitions $feed,
        private ObservationHandler $handler,
        private PaymentProjection $projection,
        private DatabaseManager $databases,
        private BilliumOptions $options,
    ) {}

    /**
     * One root operation of the observation path; the body result is returned after commit.
     *
     * @template T
     *
     * @param  Closure(TransactionContext): T  $body
     * @return T
     */
    public function root(Closure $body, string $kind = 'ledger.observation_apply', FinancialMode $mode = FinancialMode::Active, ?string $commitKind = 'ledger.observation', ?string $sourceAuthority = null, ?FinancialScope $scope = null): mixed
    {
        $caller = $this->callers->current();
        $spec = new OperationSpec($kind, $caller->sellerRef, $caller->environment, $mode, ModuleOperationClass::ObservedFactRecording, ['ledger', 'platform'],
            null, null, $commitKind, $scope ?? ($sourceAuthority === null ? null : new FinancialScope($caller->installationRef,
                $caller->sellerRef, $caller->environment, null, $sourceAuthority)));
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
     * Raw custody of one authenticated delivery: a RawCustody root takes no financial session barrier and no
     * monetary commit kind, so custody commits even while financial application is sealed. It never invokes a handler.
     */
    public function custody(stdClass $envelope, CustodyHandler $handler, ?FinancialScope $scope = null, ?string $proof = null): CustodyResult
    {
        $caller = $this->callers->current();
        $spec = new OperationSpec('platform.inbox_custody', $caller->sellerRef, $caller->environment, FinancialMode::RawCustody,
            ModuleOperationClass::CallbackCustody, ['platform'], financialScope: $scope);
        $captured = null;
        $this->runner->run($spec, function (TransactionContext $context) use ($envelope, $handler, $proof, &$captured): Receipt {
            $captured = $proof === null ? $this->feed->process($context, self::CHANNEL, $envelope, $handler)
                : $this->feed->processQualified($context, self::CHANNEL, $envelope, $handler, self::rootRef($envelope), $proof);

            return new LedgerReceipt('ledger.observation_custody', ['kind' => 'custody']);
        });

        return $captured instanceof CustodyResult ? $captured : throw new LogicException('The custody root did not run its body');
    }

    private static function rootRef(stdClass $envelope): string
    {
        $root = $envelope->originalPaymentRef ?? $envelope->operationRef ?? null;

        return is_string($root) ? $root : throw new ProblemException(ProblemCode::ValidationInvalid, 'Missing observation root');
    }

    /** The configured Billium connection (billium.connection), never the host's default database, for work selection. */
    public function connection(): Connection
    {
        return $this->databases->connection($this->options->connection);
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
                $scope = $this->feed->scopeForPartition($this->callers->current(), self::CHANNEL, $partition);
                $next[] = $this->root(fn (TransactionContext $context): CustodyResult => $this->feed->drain($context, $partition, $handler), mode: $mode, scope: $scope);
            }
            if ($current->retryInboxId !== null && ($current->disposition === CustodyDisposition::Duplicate || $current->retryInboxId !== $current->inboxId)) {
                $inbox = $current->retryInboxId;
                $next[] = $this->retryRoot($inbox, $handler, $mode);
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
        if ($caller->sourceAuthorities === []) {
            return [];
        }
        $authorities = implode(',', array_fill(0, count($caller->sourceAuthorities), '?'));
        $ready = self::DEPENDENCY_READY;
        $bindingKey = ObservationOwnership::bindingKey('o');
        $statuses = [];
        $refusal = null;
        $attemptedParks = [];
        $attemptedInboxes = [];
        while (count($statuses) < $limit) {
            // Re-select after each root so releasing an earlier bootstrap park admits its waiting revision.
            $row = $this->connection()->selectOne(<<<SQL
            SELECT k.id, k.inbox_id, o.source_authority, binding.intent_owner_namespace FROM public.billium_observation_park k
            JOIN public.billium_observed_payment o
              ON o.id = k.observed_payment_id AND o.seller_ref = k.seller_ref AND o.environment = k.environment
            LEFT JOIN public.billium_observation_owner_binding binding ON binding.operation_key={$bindingKey}
               AND binding.seller_ref=o.seller_ref AND binding.environment=o.environment
               AND binding.installation_ref=o.installation_ref AND binding.source_authority=o.source_authority AND binding.operation_ref=o.operation_ref
               AND binding.provider_account_ref=o.provider_account_ref
            WHERE (binding.intent_owner_namespace IS NULL OR binding.intent_owner_namespace IN (SELECT jsonb_array_elements_text(?::jsonb)))
              AND k.seller_ref = ? AND k.environment = ? AND k.released_at IS NULL
              AND o.installation_ref = ? AND o.source_authority IN ({$authorities})
              AND NOT (k.id = ANY(?::bigint[])) AND ({$ready})
            ORDER BY k.revision, k.id LIMIT 1
            SQL, [json_encode($caller->intentOwnerNamespaces, JSON_THROW_ON_ERROR), $caller->sellerRef, $caller->environment, $caller->installationRef, ...$caller->sourceAuthorities, '{'.implode(',', $attemptedParks).'}']);
            try {
                if ($row === null) {
                    // Custody can park a later revision before the ledger handler sees it. Recover it even if the
                    // worker that released its predecessor died after commit and before following the retry pointer.
                    $inbox = $this->feed->nextUnblockedDependency(self::CHANNEL, $caller, $attemptedInboxes);
                    if ($inbox === null) {
                        break;
                    }
                    $attemptedInboxes[] = $inbox;
                    $result = $this->retryRoot($inbox, $this->handler, $mode);
                    $statuses[] = $result->status ?? $result->disposition->value;
                    $this->follow($result, mode: $mode);

                    continue;
                }
                $park = is_object($row) && is_int($row->id ?? null) ? $row->id : throw new LogicException('Expected a park id');
                $attemptedParks[] = $park;
                $inbox = is_int($row->inbox_id ?? null) ? $row->inbox_id : null;
                if ($inbox !== null) {
                    $result = $this->retryRoot($inbox, $this->handler, $mode);
                    $statuses[] = $result->status ?? $result->disposition->value;
                    $this->follow($result, mode: $mode);
                } else {
                    $source = is_string($row->source_authority ?? null) ? $row->source_authority : throw new LogicException('Expected an observed source');
                    $owner = is_string($row->intent_owner_namespace ?? null) ? $row->intent_owner_namespace : null;
                    $scope = new FinancialScope($caller->installationRef, $caller->sellerRef, $caller->environment, $owner, $source);
                    $statuses[] = $this->root(fn (TransactionContext $context): string => $this->reproject($context, $park), mode: $mode, scope: $scope);
                }
            } catch (ProblemException $error) {
                if ($error->problem !== ProblemCode::InstallationQuarantined) {
                    throw $error;
                }
                $refusal = $error;
            }
        }
        if ($statuses === [] && $refusal !== null) {
            throw $refusal;
        }

        return $statuses;
    }

    private function retryRoot(int $inbox, CustodyHandler $handler, FinancialMode $mode): CustodyResult
    {
        $scope = $this->feed->scopeForInbox($this->callers->current(), self::CHANNEL, $inbox);

        return $this->root(fn (TransactionContext $context): CustodyResult => $this->retry($context, $inbox, $handler), mode: $mode, scope: $scope);
    }

    /** The platform retry of one parked delivery; a delivery no longer parked releases its ledger marker. */
    private function retry(TransactionContext $context, int $inboxId, CustodyHandler $handler): CustodyResult
    {
        $db = $this->databases->connection($context->connectionName());
        $caller = $context->caller();
        $ready = self::DEPENDENCY_READY;
        $waiting = $db->selectOne(<<<SQL
            SELECT 1 FROM public.billium_observation_park k
            JOIN public.billium_observed_payment o
              ON o.id = k.observed_payment_id AND o.seller_ref = k.seller_ref AND o.environment = k.environment
            WHERE k.inbox_id = ? AND k.seller_ref = ? AND k.environment = ? AND k.released_at IS NULL
              AND NOT ({$ready})
            LIMIT 1
            SQL, [$inboxId, $caller->sellerRef, $caller->environment]);
        if ($waiting !== null) {
            return new CustodyResult(CustodyDisposition::ParkedDependency, $inboxId, 'parked_dependency');
        }
        $result = $this->feed->retryDependency($context, $inboxId, $handler);
        if ($result->status !== null && $result->status !== 'parked_dependency') {
            $this->projection->release($db, $caller, null, $inboxId, $result->status);
        }

        return $result;
    }

    /** A parked bootstrap item: re-project the stored observed revision (keyed by operation/revision, own root). */
    private function reproject(TransactionContext $context, int $parkId): string
    {
        $db = $this->databases->connection($context->connectionName());
        $caller = $context->caller();
        $row = $db->table('billium_observation_park as k')
            ->join('billium_observed_payment as o', static function (JoinClause $join): void {
                $join->on('o.id', '=', 'k.observed_payment_id')->on('o.seller_ref', '=', 'k.seller_ref')->on('o.environment', '=', 'k.environment');
            })
            ->join('billium_observed_payment_revision as r', static function (JoinClause $join): void {
                $join->on('r.seller_ref', '=', 'k.seller_ref')->on('r.environment', '=', 'k.environment')
                    ->on('r.observed_payment_id', '=', 'k.observed_payment_id')->on('r.revision', '=', 'k.revision');
            })
            ->where('k.id', $parkId)->where('k.seller_ref', $caller->sellerRef)->where('k.environment', $caller->environment)->whereNull('k.released_at')
            ->first(['r.payload', 'o.installation_ref', 'o.source_authority']);
        if ($row === null) {
            return 'released';
        }
        if ($row->installation_ref !== $caller->installationRef || $row->source_authority !== $context->spec()->financialScope?->sourceAuthority || ! in_array($row->source_authority, $caller->sourceAuthorities, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Caller is not authorized for observation source authority');
        }
        $payload = json_decode(is_string($row->payload) ? $row->payload : '', false, 64, JSON_THROW_ON_ERROR);
        $observation = $payload instanceof stdClass ? ObservationHandler::decode($payload) : null;
        if ($observation === null) {
            $this->projection->release($db, $caller, $parkId, null, 'quarantined');

            return 'quarantined';
        }
        $outcome = $this->projection->apply($context, $observation);
        if ($outcome->kind === HandlerOutcomeKind::Conflict && $outcome->reference === ProblemCode::AuthDenied->value) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Caller is not authorized for observation source authority');
        }
        if ($outcome->kind === HandlerOutcomeKind::ParkedDependency) {
            return 'parked_dependency';
        }
        $status = $outcome->kind === HandlerOutcomeKind::Applied ? 'applied' : 'quarantined';
        $this->projection->release($db, $caller, $parkId, null, $status);

        return $status;
    }
}
