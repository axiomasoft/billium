<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Services;

use Billium\Collection\Contracts\CollectionSignalService;
use Billium\Collection\Data\CollectionHostSignalView;
use Billium\Collection\Data\CollectionHostStatusSnapshot;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\EvaluateHostSignals;
use Billium\Collection\Data\RecordOutcomeSignal;
use Billium\Collection\Enums\CollectionSignalKind;
use Billium\Collection\Enums\CollectionStepStatus;
use Billium\Collection\Enums\DunningCaseStatus;
use Billium\Collection\Enums\HostSnapshotFreshness;
use Billium\Collection\Internal\Canonical\CanonicalJson;
use Billium\Collection\Internal\CollectionReceipt;
use Billium\Collection\Internal\Queries\DatabaseCollectionInterventionQueries;
use Billium\Collection\Internal\Queries\DatabaseDunningQueries;
use Billium\Collection\Internal\Support\PanCvvGuard;
use Billium\Collection\Internal\Support\RowCast;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runner\LockScope;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Override;
use Packman\Attributes\Internal;
use PDO;
use stdClass;

#[Internal]
final readonly class DatabaseCollectionSignalService implements CollectionSignalService
{
    /** @var list<string> */
    private const array COLLECTION_MODULES = ['platform', 'ledger', 'execution', 'collection'];

    public function __construct(
        private DatabaseManager $databases,
        private OperationRunner $runner,
        private CallerContext $callers,
        private BilliumOptions $options,
    ) {}

    #[Override]
    public function evaluatePolicySignals(EvaluateHostSignals $command): array
    {
        $this->assertOutsideTransaction();
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->caseRef, 'caseRef');

        $asOfUtc = $command->asOf->setTimezone(new DateTimeZone('UTC'));
        $digest = CanonicalJson::digest([
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'caseRef' => $command->caseRef,
            'asOf' => RowCast::formatUtc($asOfUtc),
        ]);

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'collection.host_signal.evaluate.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Active,
                operationClass: ModuleOperationClass::FrozenIntentCompletion,
                modules: self::COLLECTION_MODULES,
                commandKey: $command->commandKey,
                digest: $digest,
                commitKind: 'collection.host_signal.evaluate',
            ),
            body: function (TransactionContext $context) use ($caller, $command, $asOfUtc): CollectionReceipt {
                $this->runner->assertCurrent($context, 'collection');
                $db = $this->databases->connection($context->connectionName());

                $caseRow = $db->table('billium_collection_dunning_case')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('case_ref', $command->caseRef)
                    ->first();

                if ($caseRow === null) {
                    throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.collection.dunning_case_not_found');
                }

                $caseId = RowCast::integer($caseRow->id, 'id');
                $locked = $context->lock(new LockRequest(
                    LockRank::DunningCase,
                    'billium_collection_dunning_case',
                    [$caseId],
                    scope: LockScope::Seller,
                    segment: DatabaseDunningCaseService::DUNNING_CASE_LOCK_SEGMENT,
                ));
                if ($locked !== [$caseId]) {
                    throw new LockPlanChanged('Dunning case vanished before signal evaluation lock');
                }

                $caseRow = $db->table('billium_collection_dunning_case')
                    ->where('id', $caseId)
                    ->first() ?? throw new LockPlanChanged('Dunning case missing after lock');
                $case = DatabaseDunningQueries::hydrateCase($caseRow);

                if (in_array($case->status, [DunningCaseStatus::Settled, DunningCaseStatus::Cancelled], true) || $case->currentOpenMinor <= 0) {
                    return new CollectionReceipt('collection.host_signal.evaluated', ['signalIds' => []]);
                }

                $policyRow = $db->table('billium_collection_dunning_policy')
                    ->where('id', $case->policyId)
                    ->first() ?? throw new LogicException('Pinned dunning policy missing');
                $policy = DatabaseDunningQueries::hydratePolicy($policyRow);

                $signalIds = [];
                $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

                if ($policy->graceSignalAfterSeconds !== null) {
                    $graceAt = $case->openedAt->modify('+'.$policy->graceSignalAfterSeconds.' seconds');
                    if ($asOfUtc >= $graceAt) {
                        $signalIds[] = self::insertSignalIdempotent(
                            db: $db,
                            sellerRef: $caller->sellerRef,
                            environment: $caller->environment,
                            dunningCaseId: $case->id,
                            caseRef: $case->caseRef,
                            billingAccountRef: $case->billingAccountRef,
                            obligationRef: $case->obligationRef,
                            componentRef: $case->componentRef,
                            kind: CollectionSignalKind::Grace,
                            dedupeKey: 'policy-grace:'.$policy->policyRef.'@'.$policy->policyVersion,
                            caseRevision: $case->caseRevision,
                            policyRef: $policy->policyRef,
                            policyVersion: $policy->policyVersion,
                            payload: [
                                'signalOnly' => true,
                                'caseStatus' => $case->status->value,
                                'currentOpenMinor' => $case->currentOpenMinor,
                                'currency' => $case->currency,
                                'graceSignalAfterSeconds' => $policy->graceSignalAfterSeconds,
                                'hostResourceMutationPermitted' => false,
                            ],
                            effectiveAt: $graceAt,
                            recordedAt: $nowUtc,
                        );
                    }
                }

                if ($policy->readOnlySignalAfterSeconds !== null) {
                    $readOnlyAt = $case->openedAt->modify('+'.$policy->readOnlySignalAfterSeconds.' seconds');
                    if ($asOfUtc >= $readOnlyAt) {
                        $signalIds[] = self::insertSignalIdempotent(
                            db: $db,
                            sellerRef: $caller->sellerRef,
                            environment: $caller->environment,
                            dunningCaseId: $case->id,
                            caseRef: $case->caseRef,
                            billingAccountRef: $case->billingAccountRef,
                            obligationRef: $case->obligationRef,
                            componentRef: $case->componentRef,
                            kind: CollectionSignalKind::ReadOnly,
                            dedupeKey: 'policy-read-only:'.$policy->policyRef.'@'.$policy->policyVersion,
                            caseRevision: $case->caseRevision,
                            policyRef: $policy->policyRef,
                            policyVersion: $policy->policyVersion,
                            payload: [
                                'signalOnly' => true,
                                'caseStatus' => $case->status->value,
                                'currentOpenMinor' => $case->currentOpenMinor,
                                'currency' => $case->currency,
                                'readOnlySignalAfterSeconds' => $policy->readOnlySignalAfterSeconds,
                                'hostResourceMutationPermitted' => false,
                            ],
                            effectiveAt: $readOnlyAt,
                            recordedAt: $nowUtc,
                        );
                    }
                }

                return new CollectionReceipt('collection.host_signal.evaluated', [
                    'signalIds' => $signalIds,
                ]);
            },
        )->orThrow();

        $rawIds = $outcome->body['signalIds'] ?? [];
        if (! is_array($rawIds) || $rawIds === []) {
            return [];
        }

        $ids = array_map(static fn (mixed $v): int => RowCast::integer($v, 'signalId'), $rawIds);
        $rows = $this->databases->connection($this->options->connection)
            ->table('billium_collection_host_signal')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = DatabaseCollectionInterventionQueries::hydrateSignal($row);
        }

        return $out;
    }

    #[Override]
    public function recordOutcomeSignal(RecordOutcomeSignal $command): CollectionHostSignalView
    {
        $this->assertOutsideTransaction();
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->caseRef, 'caseRef');
        RowCast::bounded($command->signalDedupeKey, 'signalDedupeKey');
        PanCvvGuard::assertCleanPayload($command->payload);

        if (! in_array($command->signalKind, [
            CollectionSignalKind::RenewalSucceeded,
            CollectionSignalKind::RenewalFailed,
            CollectionSignalKind::CustomerActionRequired,
        ], true)) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'billium.collection.unsupported_outcome_signal_kind',
            );
        }

        $effectiveUtc = $command->effectiveAt->setTimezone(new DateTimeZone('UTC'));
        $payload = array_merge($command->payload, ['signalOnly' => true, 'hostResourceMutationPermitted' => false]);
        $digest = CanonicalJson::digest([
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'caseRef' => $command->caseRef,
            'signalKind' => $command->signalKind->value,
            'signalDedupeKey' => $command->signalDedupeKey,
            'effectiveAt' => RowCast::formatUtc($effectiveUtc),
            'payload' => $payload,
        ]);

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'collection.host_signal.record_outcome.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Active,
                operationClass: ModuleOperationClass::FrozenIntentCompletion,
                modules: self::COLLECTION_MODULES,
                commandKey: $command->commandKey,
                digest: $digest,
                commitKind: 'collection.host_signal.record_outcome',
            ),
            body: function (TransactionContext $context) use ($caller, $command, $effectiveUtc, $payload): CollectionReceipt {
                $this->runner->assertCurrent($context, 'collection');
                $db = $this->databases->connection($context->connectionName());

                $caseRow = $db->table('billium_collection_dunning_case')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('case_ref', $command->caseRef)
                    ->first();

                if ($caseRow === null) {
                    throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.collection.dunning_case_not_found');
                }

                $caseId = RowCast::integer($caseRow->id, 'id');
                $locked = $context->lock(new LockRequest(
                    LockRank::DunningCase,
                    'billium_collection_dunning_case',
                    [$caseId],
                    scope: LockScope::Seller,
                    segment: DatabaseDunningCaseService::DUNNING_CASE_LOCK_SEGMENT,
                ));
                if ($locked !== [$caseId]) {
                    throw new LockPlanChanged('Dunning case vanished before outcome signal lock');
                }

                $caseRow = $db->table('billium_collection_dunning_case')
                    ->where('id', $caseId)
                    ->first() ?? throw new LockPlanChanged('Dunning case missing after lock');
                $case = DatabaseDunningQueries::hydrateCase($caseRow);

                if ($command->signalKind === CollectionSignalKind::RenewalSucceeded) {
                    $hasSucceededStep = $db->table('billium_collection_step')
                        ->where('dunning_case_id', $caseId)
                        ->where('status', CollectionStepStatus::Succeeded->value)
                        ->exists();
                    if ($case->status !== DunningCaseStatus::Settled && ! $hasSucceededStep) {
                        throw new ProblemException(
                            ProblemCode::OperationFinancialHold,
                            'billium.collection.renewal_succeeded_requires_verified_settlement',
                        );
                    }
                }

                $signalId = self::insertSignalIdempotent(
                    db: $db,
                    sellerRef: $caller->sellerRef,
                    environment: $caller->environment,
                    dunningCaseId: $case->id,
                    caseRef: $case->caseRef,
                    billingAccountRef: $case->billingAccountRef,
                    obligationRef: $case->obligationRef,
                    componentRef: $case->componentRef,
                    kind: $command->signalKind,
                    dedupeKey: $command->signalDedupeKey,
                    caseRevision: $case->caseRevision,
                    policyRef: $case->policyRef,
                    policyVersion: $case->policyVersion,
                    payload: $payload,
                    effectiveAt: $effectiveUtc,
                    recordedAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
                );

                return new CollectionReceipt('collection.host_signal.recorded', [
                    'signalId' => $signalId,
                ]);
            },
        )->orThrow();

        $signalId = RowCast::integer($outcome->body['signalId'] ?? null, 'signalId');
        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_host_signal')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('id', $signalId)
            ->first() ?? throw new LogicException('Recorded host signal missing');

        return DatabaseCollectionInterventionQueries::hydrateSignal($row);
    }

    #[Override]
    public function readHostStatus(
        CollectionScope $scope,
        string $caseRef,
        ?DateTimeImmutable $asOf = null,
        ?int $maxStalenessSeconds = null,
    ): CollectionHostStatusSnapshot {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($caseRef, 'caseRef');
        if ($maxStalenessSeconds !== null && $maxStalenessSeconds < 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'maxStalenessSeconds must be nonnegative');
        }

        $utc = new DateTimeZone('UTC');
        $observedAt = ($asOf ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        $db = $this->databases->connection($this->options->connection);

        $caseRow = $db->table('billium_collection_dunning_case')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('case_ref', $caseRef)
            ->first();

        if ($caseRow === null) {
            return CollectionHostStatusSnapshot::unknown($scope, $caseRef, $observedAt, 'case_not_found_or_unavailable');
        }

        $case = DatabaseDunningQueries::hydrateCase($caseRow);
        $latestStepRow = $db->table('billium_collection_step')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('dunning_case_id', $case->id)
            ->orderByDesc('step_no')
            ->first();
        $latestStepStatus = $latestStepRow === null
            ? null
            : CollectionStepStatus::from(RowCast::str($latestStepRow->status, 'status'));

        $signalRows = $db->table('billium_collection_host_signal')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('dunning_case_id', $case->id)
            ->orderBy('id')
            ->get();

        $signals = [];
        $graceActive = false;
        $readOnlyActive = false;
        foreach ($signalRows as $sRow) {
            $sig = DatabaseCollectionInterventionQueries::hydrateSignal($sRow);
            $signals[] = $sig;
            if ($sig->signalKind === CollectionSignalKind::Grace) {
                $graceActive = true;
            }
            if ($sig->signalKind === CollectionSignalKind::ReadOnly) {
                $readOnlyActive = true;
            }
        }

        $paymentSettled = $case->status === DunningCaseStatus::Settled || $latestStepStatus === CollectionStepStatus::Succeeded;
        if ($paymentSettled) {
            $graceActive = false;
            $readOnlyActive = false;
        }

        $freshness = HostSnapshotFreshness::Authoritative;
        $stalenessReason = null;
        if ($latestStepStatus === CollectionStepStatus::Unknown || $case->status === DunningCaseStatus::ParkedUnknown) {
            $freshness = HostSnapshotFreshness::Unknown;
            $stalenessReason = 'collection_outcome_unknown';
        } elseif ($maxStalenessSeconds !== null) {
            $ageSeconds = $observedAt->getTimestamp() - $case->updatedAt->getTimestamp();
            if ($ageSeconds > $maxStalenessSeconds) {
                $freshness = HostSnapshotFreshness::Stale;
                $stalenessReason = 'snapshot_age_exceeded';
            }
        }

        return new CollectionHostStatusSnapshot(
            sellerRef: $case->sellerRef,
            environment: $case->environment,
            caseRef: $case->caseRef,
            billingAccountRef: $case->billingAccountRef,
            obligationRef: $case->obligationRef,
            componentRef: $case->componentRef,
            freshness: $freshness,
            caseStatus: $case->status,
            caseRevision: $case->caseRevision,
            latestStepStatus: $latestStepStatus,
            paymentSettled: $paymentSettled,
            graceActive: $graceActive,
            readOnlyActive: $readOnlyActive,
            backupChargeAllowed: false,
            signals: $signals,
            observedAt: $observedAt,
            authoritativeAsOf: $case->updatedAt,
            stalenessReason: $stalenessReason,
        );
    }

    /**
     * Shared append-only idempotent signal insertion helper (used inside an active Collection root Tx).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function insertSignalIdempotent(
        Connection $db,
        string $sellerRef,
        string $environment,
        int $dunningCaseId,
        string $caseRef,
        string $billingAccountRef,
        string $obligationRef,
        ?string $componentRef,
        CollectionSignalKind $kind,
        string $dedupeKey,
        int $caseRevision,
        string $policyRef,
        int $policyVersion,
        array $payload,
        DateTimeImmutable $effectiveAt,
        DateTimeImmutable $recordedAt,
    ): int {
        PanCvvGuard::assertCleanPayload($payload);
        $signalRef = self::signalRef($sellerRef, $environment, $caseRef, $kind->value, $dedupeKey);
        $payloadJson = CanonicalJson::encode($payload);
        $signalDigest = CanonicalJson::digest([
            'sellerRef' => $sellerRef,
            'environment' => $environment,
            'caseRef' => $caseRef,
            'signalKind' => $kind->value,
            'signalDedupeKey' => $dedupeKey,
            'policyRef' => $policyRef,
            'policyVersion' => $policyVersion,
            'payload' => $payload,
            'effectiveAt' => RowCast::formatUtc($effectiveAt),
        ]);

        $existing = $db->table('billium_collection_host_signal')
            ->where('dunning_case_id', $dunningCaseId)
            ->where('signal_kind', $kind->value)
            ->where('signal_dedupe_key', $dedupeKey)
            ->first();

        if ($existing !== null) {
            return RowCast::integer($existing->id, 'id');
        }

        $inserted = $db->selectOne(
            <<<'SQL'
            INSERT INTO public.billium_collection_host_signal (
                seller_ref, environment, signal_ref,
                dunning_case_id, case_ref, billing_account_ref, obligation_ref, component_ref,
                signal_kind, signal_dedupe_key, case_revision, policy_ref, policy_version,
                signal_only, payload, signal_digest, effective_at, recorded_at
            ) VALUES (
                ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                true, ?::jsonb, ?, ?, ?
            )
            ON CONFLICT (dunning_case_id, signal_kind, signal_dedupe_key) DO NOTHING
            RETURNING id
            SQL,
            [
                $sellerRef,
                $environment,
                $signalRef,
                $dunningCaseId,
                $caseRef,
                $billingAccountRef,
                $obligationRef,
                $componentRef,
                $kind->value,
                $dedupeKey,
                $caseRevision,
                $policyRef,
                $policyVersion,
                $payloadJson,
                $signalDigest,
                RowCast::formatUtc($effectiveAt),
                RowCast::formatUtc($recordedAt),
            ],
        );

        if ($inserted instanceof stdClass) {
            return RowCast::integer($inserted->id, 'id');
        }

        $raced = $db->table('billium_collection_host_signal')
            ->where('dunning_case_id', $dunningCaseId)
            ->where('signal_kind', $kind->value)
            ->where('signal_dedupe_key', $dedupeKey)
            ->first() ?? throw new LogicException('Host signal vanished after conflict');

        return RowCast::integer($raced->id, 'id');
    }

    public static function signalRef(
        string $sellerRef,
        string $environment,
        string $caseRef,
        string $signalKind,
        string $dedupeKey,
    ): string {
        return 'col-signal:'.hash('sha256', CanonicalJson::encode([
            $sellerRef,
            $environment,
            $caseRef,
            $signalKind,
            $dedupeKey,
        ]));
    }

    private function assertOutsideTransaction(): void
    {
        foreach ($this->databases->getConnections() as $connection) {
            $writePdo = $connection->getRawPdo();
            $readPdo = $connection->getRawReadPdo();
            if (
                $connection->transactionLevel() !== 0
                || ($writePdo instanceof PDO && $writePdo->inTransaction())
                || ($readPdo instanceof PDO && $readPdo->inTransaction())
            ) {
                throw new LogicException('Collection signal operations must run outside every database transaction');
            }
        }
    }
}
