<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Services;

use Billium\Core\Contracts\CallerContext;
use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\OperationSpec;
use Billium\Core\Enums\FinancialMode;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Usage\Contracts\AggregationService;
use Billium\Usage\Data\FreezeAggregate;
use Billium\Usage\Data\FreezeReceipt;
use Billium\Usage\Enums\LatePolicy;
use Billium\Usage\Internal\Canonical\CanonicalJson;
use Billium\Usage\Internal\Errors\UsageProblems;
use Billium\Usage\Internal\Storage\AggregateStore;
use Billium\Usage\Internal\Storage\AggregationRows;
use Billium\Usage\Internal\Storage\EffectiveFactSource;
use Billium\Usage\Internal\Window\WindowQualifier;

final readonly class DatabaseAggregationService implements AggregationService
{
    public function __construct(
        private OperationRunner $runner,
        private CallerContext $callers,
        private AggregateStore $store,
        private EffectiveFactSource $facts,
        private SnapshotBuilder $builder,
        private WindowQualifier $qualifier,
    ) {}

    public function freeze(FreezeAggregate $command): FreezeReceipt
    {
        $window = $command->window;
        $this->store->authorize($window);
        $caller = $this->callers->current();
        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'usage.aggregate.freeze.v1', sellerRef: $caller->sellerRef, environment: $caller->environment,
                mode: FinancialMode::Administrative, operationClass: ModuleOperationClass::NewIntent, modules: ['platform', 'usage'],
                commandKey: $command->commandKey,
                digest: $command->commandKey === null ? null : CanonicalJson::digest([
                    'window' => $window->payload(), 'expectedHeadDigest' => $command->expectedHeadDigest,
                ]),
            ),
            body: function (TransactionContext $context) use ($command, $window): FreezeReceipt {
                $this->runner->assertCurrent(context: $context, moduleId: 'usage');
                $db = $this->store->database($context->connectionName());
                $policy = $this->store->policy(db: $db, window: $window);
                if ($this->facts->metricDefinitionDigest(
                    sellerRef: $window->sellerRef, metricRef: $window->metricRef,
                    metricVersion: $window->metricVersion, connectionName: $context->connectionName(),
                ) !== $policy->metricDefinitionDigest) {
                    throw UsageProblems::error('snapshot_digest_mismatch');
                }
                $contract = $this->store->contract(db: $db, window: $window, policy: $policy);
                $evidence = $this->store->evidence(db: $db, window: $window, sources: $contract->requiredSources);
                $qualification = $this->qualifier->qualify(
                    start: $window->startMicros, end: $window->endMicros, requiredSources: $contract->requiredSources,
                    graceMicros: $contract->lateGraceMicros, evidence: $evidence,
                );
                if (! $qualification->qualified) {
                    throw UsageProblems::windowHeld(reasons: $qualification->reasons, repairRefs: $qualification->repairRefs);
                }
                $head = $this->store->lockHead(context: $context, window: $window);
                $previous = $this->store->headSnapshot(db: $db, head: $head);
                if ($previous !== null) {
                    $this->store->assertReproducible(db: $db, snapshot: $previous);
                }
                $revisions = $this->facts->revisionsForWindow(window: $window, connectionName: $context->connectionName());
                if ($previous !== null) {
                    $this->store->assertRetained(db: $db, snapshot: $previous, revisions: $revisions);
                }
                $snapshot = $this->builder->build(
                    policy: $policy, contract: $contract, window: $window, revisions: $revisions, evidence: $evidence,
                    headRevision: AggregationRows::integer($head->head_revision),
                    headDigest: $head->head_digest === null ? null : AggregationRows::text($head->head_digest),
                );
                if ($head->head_digest === $snapshot->snapshotDigest && $previous !== null) {
                    return FreezeReceipt::snapshot(snapshot: $this->store->hydrate(db: $db, snapshot: $previous), created: false);
                }
                if ($command->expectedHeadDigest !== null && $command->expectedHeadDigest !== $head->head_digest) {
                    throw UsageProblems::error('snapshot_superseded');
                }
                if ($previous !== null) {
                    if ($contract->postFinalPolicy === LatePolicy::ManualReview) {
                        throw UsageProblems::error('late_manual_review');
                    }
                    if ($contract->postFinalPolicy === LatePolicy::Reject || ! $contract->reopenAllowed) {
                        throw UsageProblems::error('late_rejected');
                    }
                }
                $this->store->insert(
                    context: $context, snapshot: $snapshot, policy: $policy,
                    effective: $this->builder->effective(policy: $policy, window: $window, revisions: $revisions), head: $head,
                );

                return FreezeReceipt::snapshot(snapshot: $snapshot, created: true);
            },
        );
        $outcome->orThrow();

        return new FreezeReceipt($outcome->body);
    }
}
