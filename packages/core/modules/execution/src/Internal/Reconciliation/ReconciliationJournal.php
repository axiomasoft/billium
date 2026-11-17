<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Reconciliation;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\UnresolvedDisposition;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\ReconciliationRun;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Billium\Platform\Contracts\Installation\Installation;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class ReconciliationJournal
{
    public function __construct(private OperationRunner $runner, private CallerContext $callers, private Installation $installation,
        private DatabaseManager $databases, private ExecutionStore $store) {}

    /**
     * @param  Closure(TransactionContext):ExecutionReceipt  $body
     * @param  list<string>  $modules
     */
    public function write(ExecutionScope $scope, string $source, string $kind, Closure $body, ?string $key = null, ?string $digest = null, array $modules = ['execution', 'platform']): StoredOutcome
    {
        $selection = PaymentIdentity::select($this->callers->current(), $scope, $source);
        $session = $this->installation->currentSession($scope->installationRef);
        $mode = $session?->state === 'recovery' ? FinancialMode::Recovery : FinancialMode::Active;

        return $this->runner->run(new OperationSpec($kind, $scope->sellerRef, $scope->environment, $mode, ModuleOperationClass::UnknownResolution,
            $modules, $key, $digest, $kind, $selection), $body)->orThrow();
    }

    public function db(TransactionContext $ctx): Connection
    {
        $this->runner->assertCurrent($ctx, 'execution');
        $ctx->sessionEpoch();

        return $this->databases->connection($ctx->connectionName());
    }

    /** Serialize only local run bookkeeping under the owner namespace. */
    public function current(TransactionContext $ctx, RecoveryWindow $window): stdClass
    {
        $selected = PaymentIdentity::select($ctx->caller(), $window->scope, $window->source);
        if ($ctx->spec()->financialScope === null || get_object_vars($ctx->spec()->financialScope) !== get_object_vars($selected)
            || ! in_array('execution.reconcile', $ctx->caller()->permissions, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Recovery journal requires the current selected owner, source and action permission');
        }
        $db = $this->db($ctx);
        $this->lockNamespace($ctx, $window->scope);
        $q = $db->table('billium_execution_reconciliation_run')->where($window->scopeFields())->where('run_ref', $window->ref);
        $row = $q->first();
        if ($row === null) {
            $bind = $window->bindingFields();
            $this->overlap($db, $window);
            $run = new ReconciliationRun($window->ref, $window->scope, $window->merchant->merchantAccountRef, $window->from,
                null, null, null, null, 'running', [], new DateTimeImmutable, null);
            $this->store->appendReconciliationRun($ctx, $run);
            $row = $q->first() ?? throw new LogicException('Missing inserted run');
            $db->table('billium_execution_recon_binding')->insert($bind + ['run_id' => $row->id, 'through_at' => self::stamp($window->through)]);
        }
        $bound = $db->table('billium_execution_recon_binding')->where('run_id', $row->id)->where($window->bindingFields())->first();
        if ($bound === null || ! is_string($bound->through_at) || ! is_string($row->recovery_horizon)
            || new DateTimeImmutable($bound->through_at)->format('U.u') !== $window->through->format('U.u') || new DateTimeImmutable($row->recovery_horizon)->format('U.u') !== $window->from->format('U.u')
            || $row->merchant_account_ref !== $window->merchant->merchantAccountRef) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'Recovery run identity differs from its original horizon or account');
        }

        return $row;
    }

    private function overlap(Connection $db, RecoveryWindow $window): void
    {
        if ($window->attempt !== null) {
            return;
        }
        $prior = $db->table('billium_execution_recon_binding as b')->join('billium_execution_reconciliation_run as r', 'r.id', '=', 'b.run_id')
            ->where($window->scopeFields())->where($window->bindingFields())->where('r.state', 'complete')->max('through_at');
        if (is_string($prior) && ($window->from >= new DateTimeImmutable($prior) || $window->through < new DateTimeImmutable($prior))) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Inventory must overlap the prior covered horizon');
        }
    }

    public function lockNamespace(TransactionContext $ctx, ExecutionScope $scope): void
    {
        $id = $this->db($ctx)->table('billium_execution_namespace')->where(['installation_ref' => $scope->installationRef,
            'seller_ref' => $scope->sellerRef, 'environment' => $scope->environment, 'intent_owner_namespace' => $scope->intentOwnerNamespace])->value('id');
        if ((! is_int($id) && ! is_string($id)) || $ctx->lock(new LockRequest(LockRank::IntentNamespace, 'billium_execution_namespace', [(int) $id])) !== [(int) $id]) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Recovery needs the retained original namespace');
        }
    }

    public function lockRoot(TransactionContext $ctx, ExecutionScope $scope, string $root): void
    {
        $plan = $this->store->plan($scope, $root, true, $ctx);
        foreach ($plan->participatingModuleIds as $moduleId) {
            $ctx->assertParticipant($moduleId);
        }
        foreach ($plan->locks as $lock) {
            if ($lock->rank === LockRank::FeedPartition) {
                $ctx->lock($lock);
            }
        }
        $this->store->lock($ctx, $this->store->plan($scope, $root, true, $ctx));
    }

    public static function stamp(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s.uP');
    }

    public static function integer(mixed $field): int
    {
        return is_int($field) || (is_string($field) && ctype_digit($field)) ? (int) $field : throw new LogicException('Unexpected recovery row identity');
    }

    public static function string(mixed $field): string
    {
        return is_string($field) ? $field : throw new LogicException('Unexpected recovery journal field type');
    }

    public function finish(RecoveryWindow $w, ?string $cursor, string $state, ?string $next, ?string $watermark, ?string $pageDigest): StoredOutcome
    {
        return $this->write($w->scope, $w->source, 'execution.advance_reconciliation', function (TransactionContext $ctx) use ($w, $cursor, $state, $next, $watermark, $pageDigest): ExecutionReceipt {
            $row = $this->current($ctx, $w);

            return $this->finishIn($ctx, $w, $row, $cursor, $state, $next, $watermark, $pageDigest);
        });
    }

    public function finishIn(TransactionContext $ctx, RecoveryWindow $w, stdClass $row, ?string $cursor, string $state, ?string $next,
        ?string $watermark, ?string $pageDigest): ExecutionReceipt
    {
        if ($row->state === 'complete' || $row->inventory_cursor !== $cursor) {
            return $w->receipt(self::string($row->state));
        }
        $digest = $pageDigest === null ? $row->inventory_digest : ExecutionCodec::digest([$row->inventory_digest, $pageDigest]);
        $unresolved = [];
        $manifest = null;
        if ($state === 'complete') {
            $db = $this->db($ctx);
            $this->overlap($db, $w);
            $pages = $db->table('billium_execution_recon_page')->where('run_id', $row->id);
            $missing = $db->table('billium_execution_recon_page as p')->leftJoin('billium_execution_recon_item as i', function (JoinClause $join): void {
                $join->on('i.run_id', '=', 'p.run_id')->on('i.page_no', '=', 'p.page_no');
            })->selectRaw('1')->where('p.run_id', $row->id)->groupBy('p.id', 'p.item_count')
                ->havingRaw('COUNT(i.id) <> p.item_count OR COALESCE(MAX(i.item_no) + 1, 0) <> p.item_count')->exists();
            if ($missing || ! $pages->exists()) {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Inventory lacks all items of the retained pages');
            }
            // This is the local run's comparison manifest. External source-journal attestation is separate.
            $hash = hash_init('sha256');
            hash_update($hash, ExecutionCodec::encode(['schema' => 'billium.execution.comparison-manifest.v1', 'scope' => $w->scope,
                'binding' => $w->bindingFields(), 'from' => $w->from, 'through' => $w->through, 'inventoryDigest' => $digest])."\n");
            $lines = $db->table('billium_execution_recon_item')->where('run_id', $row->id)->orderBy('page_no')->orderBy('item_no')
                ->select('page_no', 'item_no', 'fact_ref', 'evidence_ref', 'source_digest', 'comparison_basis', 'observed', 'disposition', 'review_ref');
            foreach ($lines->cursor() as $line) {
                hash_update($hash, ExecutionCodec::encode($line)."\n");
            }
            $manifest = 'sha256:'.hash_final($hash);
            $reviews = $db->table('billium_execution_recon_review as r')->where($w->scopeFields())
                ->where(['owner_namespace' => $w->scope->intentOwnerNamespace, 'source_authority' => $w->source,
                    'revision_ref' => $w->merchant->revisionRef, 'merchant_account_ref' => $w->merchant->merchantAccountRef,
                    'currency' => $w->currency, 'status' => 'open'])
                ->whereNotExists(static function (Builder $query): void {
                    $query->selectRaw('1')->from('billium_execution_recon_review as later')->whereColumn('later.id', '>', 'r.id');
                    foreach (['installation_ref', 'seller_ref', 'environment', 'owner_namespace', 'source_authority', 'case_ref'] as $key) {
                        $query->whereColumn('later.'.$key, 'r.'.$key);
                    }
                })->orderBy('id');
            if ($w->attempt !== null) {
                $reviews->where('observed->attemptRef', $w->attempt);
            }
            foreach ($reviews->cursor() as $review) {
                $observed = ExecutionCodec::map(self::string($review->observed));
                $fact = $observed['factRef'] ?? $observed['attemptRef'] ?? $review->evidence_ref;
                $unresolved[] = new UnresolvedDisposition(self::string($fact), $w->merchant->merchantAccountRef, 'held', 'manual_review', null, self::string($review->event_ref));
            }
        }
        $run = new ReconciliationRun($w->ref, $w->scope, $w->merchant->merchantAccountRef, $w->from, $next, $watermark,
            is_string($digest) ? $digest : null, $manifest,
            $state, $unresolved, new DateTimeImmutable(self::string($row->started_at)), $state === 'complete' ? new DateTimeImmutable : null);
        $this->store->advanceReconciliationRun($ctx, $run, self::string($row->state));

        return $w->receipt($state);
    }
}
