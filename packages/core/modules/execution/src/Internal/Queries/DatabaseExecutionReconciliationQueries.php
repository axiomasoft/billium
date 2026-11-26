<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Queries;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Execution\Contracts\ExecutionReconciliationQueries;
use Billium\Execution\Data\ExecutionRecoveryQueuePage;
use Billium\Execution\Data\ExecutionRecoveryQueueRow;
use Billium\Execution\Data\ExecutionReviewQueuePage;
use Billium\Execution\Data\ExecutionReviewQueueRow;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Billium\Execution\Internal\Storage\ExecutionRows;
use DateTimeImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use JsonException;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class DatabaseExecutionReconciliationQueries implements ExecutionReconciliationQueries
{
    private const string DUE = "(CASE WHEN w.state='leased' THEN w.lease_expires_at ELSE w.due_at END)";

    private const array SCOPE_COLUMNS = ['installation_ref', 'seller_ref', 'environment'];

    public function __construct(private ExecutionReads $reads, private CallerContext $callers, private Encrypter $encrypter) {}

    public function dueRecovery(ExecutionScope $scope, string $sourceAuthority, AttemptState $state, DateTimeImmutable $asOf, int $limit = 100, ?string $cursor = null): ExecutionRecoveryQueuePage
    {
        $held = $this->authorize($scope, $sourceAuthority, $limit);
        if (! in_array($state, [AttemptState::Admitted, AttemptState::Dispatching, AttemptState::Unknown, AttemptState::Pending], true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Recovery queue requires a nonterminal attempt state');
        }
        $context = ExecutionCodec::digest(['dueRecovery', $scope, $sourceAuthority, $state, $asOf->format('U.u'), $held]);

        return $this->reads->snapshot(function (Connection $db) use ($scope, $sourceAuthority, $state, $asOf, $limit, $cursor, $held, $context): ExecutionRecoveryQueuePage {
            // Seek due work in clock order and validate each candidate through its one original attempt.
            $original = $db->table('billium_execution_attempt as a')
                ->whereColumn('a.attempt_ref', 'w.attempt_ref')->whereColumn('a.operation_ref', 'w.operation_ref')
                ->join('billium_execution_operation as o', static function (JoinClause $join): void {
                    self::joinScope($join, 'o', 'a');
                    $join->on('o.operation_ref', '=', 'a.operation_ref')->on('o.merchant_account_ref', '=', 'a.merchant_account_ref');
                })->join('billium_execution_namespace as n', static function (JoinClause $join): void {
                    self::joinScope($join, 'n', 'o');
                    $join->on('n.intent_owner_namespace', '=', 'o.intent_owner_namespace');
                })->where('o.intent_owner_namespace', $scope->intentOwnerNamespace)->where('o.binding->executionProfile', $sourceAuthority)->where('a.state', $state->value)
                ->select('o.operation_ref', 'o.root_operation_ref', 'a.attempt_ref', 'a.state', 'n.state as namespace_state', 'o.intent_owner_namespace',
                    'o.merchant_account_ref', 'o.merchant_revision_ref', 'a.provider_id', 'o.binding_digest', 'o.currency', 'o.created_at', 'o.last_reconciled_at', 'a.finish_evidence_ref')
                ->limit(1);
            foreach (self::SCOPE_COLUMNS as $key) {
                $original->whereColumn('a.'.$key, 'w.'.$key);
            }
            if (! $held) {
                $original->where('n.state', 'open');
            }
            $query = $db->table('billium_execution_dispatch_work as w')->joinLateral($original, 'original')
                ->where('w.installation_ref', $scope->installationRef)->where('w.seller_ref', $scope->sellerRef)->where('w.environment', $scope->environment)
                ->whereRaw("w.state IN ('due','leased')")->select('w.id', 'original.*', 'w.attempt_count', 'w.last_error_code', 'w.last_error_at');
            [$rows, $next] = $this->page($query, self::DUE, 'w.id', $asOf, $context, $limit, $cursor);
            $result = [];
            foreach ($rows as $r) {
                $result[] = new ExecutionRecoveryQueueRow(ExecutionCodec::text($r->operation_ref), ExecutionCodec::text($r->root_operation_ref),
                    ExecutionCodec::text($r->attempt_ref), ExecutionCodec::text($r->state), ExecutionCodec::text($r->namespace_state), ExecutionCodec::text($r->intent_owner_namespace),
                    ExecutionCodec::text($r->merchant_account_ref), ExecutionCodec::text($r->merchant_revision_ref), ExecutionCodec::text($r->provider_id),
                    ExecutionCodec::text($r->binding_digest), ExecutionCodec::text($r->currency), ExecutionRows::date($r->sort_at), ExecutionRows::nullableDate($r->last_reconciled_at),
                    ExecutionRows::integer($r->attempt_count), max(0, $asOf->getTimestamp() - ExecutionRows::date($r->created_at)->getTimestamp()),
                    ExecutionRows::nullableText($r->last_error_code), ExecutionRows::nullableDate($r->last_error_at), ExecutionRows::nullableText($r->finish_evidence_ref));
            }

            return new ExecutionRecoveryQueuePage($result, $next);
        });
    }

    public function openDiscrepancies(ExecutionScope $scope, string $sourceAuthority, string $status, DateTimeImmutable $createdBefore, int $limit = 100, ?string $cursor = null): ExecutionReviewQueuePage
    {
        return $this->reviews($scope, $sourceAuthority, $status, $createdBefore, false, $limit, $cursor);
    }

    public function dueReview(ExecutionScope $scope, string $sourceAuthority, DateTimeImmutable $asOf, int $limit = 100, ?string $cursor = null): ExecutionReviewQueuePage
    {
        return $this->reviews($scope, $sourceAuthority, 'open', $asOf, true, $limit, $cursor);
    }

    private function reviews(ExecutionScope $scope, string $source, string $status, DateTimeImmutable $asOf, bool $due, int $limit, ?string $cursor): ExecutionReviewQueuePage
    {
        $this->authorize($scope, $source, $limit);
        if (! in_array($status, ['open', 'resolved'], true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Unknown discrepancy status');
        }
        $context = ExecutionCodec::digest([$due ? 'dueReview' : 'openDiscrepancies', $scope, $source, $status, $asOf->format('U.u')]);

        return $this->reads->snapshot(function (Connection $db) use ($scope, $source, $status, $asOf, $due, $context, $limit, $cursor): ExecutionReviewQueuePage {
            $query = $db->table('billium_execution_recon_review as r')->select('r.*')
                ->where('r.installation_ref', $scope->installationRef)->where('r.seller_ref', $scope->sellerRef)->where('r.environment', $scope->environment)
                ->where('r.owner_namespace', $scope->intentOwnerNamespace)->where('r.source_authority', $source)->where('r.status', $status)
                ->whereNotExists(static function (Builder $q): void {
                    $q->selectRaw('1')->from('billium_execution_recon_review as later')->whereColumn('later.id', '>', 'r.id');
                    foreach ([...self::SCOPE_COLUMNS, 'owner_namespace', 'source_authority', 'case_ref'] as $key) {
                        $q->whereColumn('later.'.$key, 'r.'.$key);
                    }
                });
            [$rows, $next] = $this->page($query, $due ? 'r.next_review_at' : 'r.created_at', 'r.id', $asOf, $context, $limit, $cursor);
            $result = [];
            foreach ($rows as $r) {
                $created = ExecutionRows::date($r->created_at);
                $result[] = new ExecutionReviewQueueRow(ExecutionCodec::text($r->case_ref), ExecutionCodec::text($r->event_ref), ExecutionCodec::text($r->status),
                    ExecutionCodec::text($r->code), ExecutionCodec::text($r->severity), ExecutionCodec::text($r->owner_ref), ExecutionCodec::text($r->merchant_account_ref),
                    ExecutionCodec::text($r->revision_ref), ExecutionCodec::text($r->currency), ExecutionRows::nullableText($r->root_ref), ExecutionCodec::text($r->evidence_ref),
                    ExecutionCodec::text($r->source_digest), ExecutionCodec::text($r->basis_digest), ExecutionCodec::map(ExecutionCodec::text($r->expected)),
                    ExecutionCodec::map(ExecutionCodec::text($r->observed)), ExecutionCodec::text($r->provenance), ExecutionRows::nullableText($r->receipt_ref),
                    $created, ExecutionRows::nullableDate($r->next_review_at), max(0, $asOf->getTimestamp() - $created->getTimestamp()));
            }

            return new ExecutionReviewQueuePage($result, $next);
        });
    }

    private function authorize(ExecutionScope $scope, string $source, int $limit): bool
    {
        $caller = $this->callers->current();
        PaymentIdentity::select($caller, $scope, $source);
        if (! in_array('execution.reconcile', $caller->permissions, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Recovery queues require the current reconciliation grant');
        }
        if ($limit < 1 || $limit > 100) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Queue page size must be between 1 and 100');
        }

        return in_array('execution.recover_held', $caller->permissions, true);
    }

    private static function joinScope(JoinClause $join, string $left, string $right): void
    {
        foreach (self::SCOPE_COLUMNS as $key) {
            $join->on($left.'.'.$key, '=', $right.'.'.$key);
        }
    }

    /**
     * @param  literal-string  $sort
     * @param  literal-string  $id
     * @return array{list<stdClass>,?string}
     */
    private function page(Builder $query, string $sort, string $id, DateTimeImmutable $until, string $context, int $limit, ?string $cursor): array
    {
        if ($cursor !== null) {
            try {
                $decoded = strlen($cursor) <= 4096 ? $this->encrypter->decrypt($cursor, false) : null;
                $tuple = is_string($decoded) ? json_decode($decoded, true, 4, JSON_THROW_ON_ERROR) : null;
            } catch (DecryptException|JsonException) {
                $tuple = null;
            }
            if (! is_array($tuple) || ($tuple[0] ?? null) !== $context || ! is_string($tuple[1] ?? null) || ! is_int($tuple[2] ?? null)) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid cursor for this recovery queue and scope');
            }
            $query->whereRaw('('.$sort.','.$id.') > (?::timestamptz,?::bigint)', [$tuple[1], $tuple[2]]);
        }
        $rows = $query->selectRaw($sort.' as sort_at')->whereRaw($sort.' <= ?::timestamptz', [ExecutionRows::stamp($until)])
            ->orderByRaw($sort.','.$id)->limit($limit + 1)->get()->values()->all();
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $last = $rows[$limit - 1];
            $next = $this->encrypter->encrypt(json_encode([$context, ExecutionCodec::text($last->sort_at), ExecutionRows::integer($last->id)], JSON_THROW_ON_ERROR), false);
        }

        return [array_values($rows), $next];
    }
}
