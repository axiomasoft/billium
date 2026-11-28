<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Work;

use Billium\Collection\Internal\CollectionReceipt;
use Billium\Collection\Internal\Support\RowCast;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Platform\Contracts\Installation\Installation;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

/** Bounded worker claims with a financial epoch and a monotonically increasing lease fence. */
#[Internal]
final readonly class CollectionWork
{
    public function __construct(
        private DatabaseManager $databases,
        private OperationRunner $runner,
        private Installation $installation,
    ) {}

    /** @return array{claims: list<CollectionWorkClaim>, exhausted: int} */
    public function claim(FinancialScope $scope, int $limit, int $leaseSeconds, ?int $parkedStepId = null): array
    {
        if ($limit < 1 || $limit > 100 || $leaseSeconds < 1 || $leaseSeconds > 300) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid collection worker limit or lease');
        }
        $session = $this->installation->currentSession($scope->installationRef);
        $mode = match ($session?->state) {
            'active' => FinancialMode::Active,
            'recovery' => FinancialMode::Recovery,
            default => throw new ProblemException(ProblemCode::InstallationQuarantined, 'Collection worker session is closed'),
        };
        $epoch = $session->epoch;
        $claims = [];
        $exhausted = 0;
        $this->runner->run(self::spec('collection.claim_work', $scope, $mode), function (TransactionContext $ctx) use (
            $scope, $epoch, $mode, $limit, $leaseSeconds, $parkedStepId, &$claims, &$exhausted,
        ): CollectionReceipt {
            $claims = [];
            $exhausted = 0;
            if ($ctx->sessionEpoch() !== $epoch) {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Collection worker epoch changed before claim');
            }
            $db = $this->databases->connection($ctx->connectionName());
            $selected = $db->select('SELECT lock_key FROM public.billium_collection_select_work(?, ?, ?, ?, ?, ?, ?, ?)', [
                $scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, $scope->sourceAuthority,
                $mode === FinancialMode::Recovery, $parkedStepId, $limit,
            ]);
            if ($selected === []) {
                return new CollectionReceipt('collection.work.claimed', ['count' => 0]);
            }
            $keys = [];
            foreach ($selected as $row) {
                if (! $row instanceof stdClass) {
                    throw new LogicException('Invalid collection work selection row');
                }
                $keys[] = RowCast::integer($row->lock_key, 'lock_key');
            }
            $lock = self::lockRequest($keys);
            if ($ctx->lock($lock) !== $lock->keys) {
                throw new LockPlanChanged('Selected collection work changed before claim');
            }
            $rows = $db->table('billium_collection_work as w')
                ->join('billium_collection_authorization as a', 'a.step_id', '=', 'w.step_id')
                ->join('billium_collection_step as s', 's.id', '=', 'w.step_id')
                ->join('billium_collection_dunning_case as c', 'c.id', '=', 'a.case_id')
                ->leftJoin('billium_collection_dispatch_permit as p', 'p.step_id', '=', 'w.step_id')
                ->whereIn('w.step_id', $keys)->orderBy('w.next_attempt_at')->orderBy('w.step_id')
                ->get(['a.*', 'w.fence', 'w.delivery_claims', 'p.permit_ref', 's.status as step_status', 's.step_ref',
                    's.delivery_attempts_used', 's.max_delivery_retries', 'c.recovery_deadline_at']);
            foreach ($rows as $row) {
                $work = $db->table('billium_collection_work')->where('step_id', $row->step_id);
                $used = RowCast::integer($row->delivery_claims, 'delivery_claims');
                $fence = RowCast::integer($row->fence, 'fence') + 1;
                $delivery = $parkedStepId === null && $row->permit_ref === null && $row->step_status === 'authorized'
                    && $row->financial_epoch === $ctx->sessionEpoch();
                if ($delivery && $used > RowCast::integer($row->max_delivery_retries, 'max_delivery_retries')
                    - RowCast::integer($row->delivery_attempts_used, 'delivery_attempts_used')) {
                    $work->update(['status' => 'parked', 'next_attempt_at' => null, 'fence' => $fence, 'last_reason' => 'delivery_budget_exhausted']);
                    $exhausted++;

                    continue;
                }
                $until = new DateTimeImmutable('+'.$leaseSeconds.' seconds');
                $work->update(['status' => 'leased', 'fence' => $fence, 'next_attempt_at' => RowCast::formatUtc($until),
                    'delivery_claims' => $used + (int) $delivery]);
                $claims[] = new CollectionWorkClaim($scope, $mode, $ctx->sessionEpoch(), $fence, $until, $row);
            }

            return new CollectionReceipt('collection.work.claimed', ['count' => count($claims)]);
        })->orThrow();

        return ['claims' => $claims, 'exhausted' => $exhausted];
    }

    public function owns(TransactionContext $ctx, CollectionWorkClaim $claim): bool
    {
        $scope = $ctx->spec()->financialScope;

        return $ctx->sessionEpoch() === $claim->epoch && $scope !== null && get_object_vars($scope) === get_object_vars($claim->scope)
            && $ctx->lock(self::lockRequest([$claim->stepId])) === [$claim->stepId] && $this->fenced($ctx, $claim)->exists();
    }

    public function finish(CollectionWorkClaim $claim, string $status, ?DateTimeImmutable $next, ?string $reason): bool
    {
        $outcome = $this->runner->run(self::spec('collection.finish_work', $claim->scope, $claim->mode),
            function (TransactionContext $ctx) use ($claim, $status, $next, $reason): CollectionReceipt {
                $updated = $ctx->sessionEpoch() === $claim->epoch && $this->fenced($ctx, $claim)->update([
                    'status' => $status, 'next_attempt_at' => $next === null ? null : RowCast::formatUtc($next), 'last_reason' => $reason,
                ]) === 1;

                return new CollectionReceipt('collection.work.finished', ['updated' => $updated]);
            })->orThrow();

        return $outcome->body['updated'] === true;
    }

    private function fenced(TransactionContext $ctx, CollectionWorkClaim $claim): Builder
    {
        $this->runner->assertCurrent($ctx, 'collection');

        return $this->databases->connection($ctx->connectionName())->table('billium_collection_work')
            ->where('step_id', $claim->stepId)->where('seller_ref', $ctx->caller()->sellerRef)
            ->where('environment', $ctx->caller()->environment)
            ->where('status', 'leased')->where('fence', $claim->fence)->whereRaw('next_attempt_at > clock_timestamp()');
    }

    /** @param list<int> $keys */
    private static function lockRequest(array $keys): LockRequest
    {
        return new LockRequest(LockRank::DunningCase, 'billium_collection_work', $keys, keyColumn: 'step_id', segment: 3);
    }

    private static function spec(string $kind, FinancialScope $scope, FinancialMode $mode): OperationSpec
    {
        if ($scope->sellerRef === null || $scope->environment === null || $scope->intentOwnerNamespace === null || $scope->sourceAuthority === null) {
            throw new LogicException('Collection claims need an exact financial scope');
        }

        return new OperationSpec($kind, $scope->sellerRef, $scope->environment, $mode, ModuleOperationClass::UnknownResolution,
            ['platform', 'ledger', 'execution', 'collection'], financialScope: $scope);
    }
}
