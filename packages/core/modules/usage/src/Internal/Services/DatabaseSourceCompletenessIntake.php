<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Services;

use Billium\Core\Contracts\CallerContext;
use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\LockRequest;
use Billium\Core\Data\OperationSpec;
use Billium\Core\Enums\FinancialMode;
use Billium\Core\Enums\LockRank;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Usage\Contracts\SourceCompletenessIntake;
use Billium\Usage\Data\Commands\RecordSourceCompleteness;
use Billium\Usage\Data\FreezeReceipt;
use Billium\Usage\Internal\Canonical\CanonicalJson;
use Billium\Usage\Internal\Errors\UsageProblems;
use Billium\Usage\Internal\Storage\AggregateStore;

final readonly class DatabaseSourceCompletenessIntake implements SourceCompletenessIntake
{
    public function __construct(private OperationRunner $runner, private CallerContext $callers, private AggregateStore $store) {}

    public function record(RecordSourceCompleteness $command): FreezeReceipt
    {
        $caller = $this->callers->current();
        $evidence = $command->evidence;
        if ($caller->sellerRef !== $command->sellerRef || $caller->environment !== $command->environment
            || ! in_array($evidence->sourceRef, $caller->sourceAuthorities, true)) {
            throw UsageProblems::error('scope_denied');
        }
        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'usage.completeness.record.v1', sellerRef: $caller->sellerRef, environment: $caller->environment,
                mode: FinancialMode::Administrative, operationClass: ModuleOperationClass::NewIntent, modules: ['platform', 'usage'],
                commandKey: CanonicalJson::digest([$evidence->sourceRef, $evidence->evidenceSeq]), digest: $evidence->evidenceDigest,
            ),
            body: function (TransactionContext $context) use ($command, $evidence): FreezeReceipt {
                $this->runner->assertCurrent(context: $context, moduleId: 'usage');
                $db = $this->store->database($context->connectionName());
                $db->statement(query: 'INSERT INTO public.billium_usage_source_head (seller_ref,environment,source_ref)
                    VALUES (?,?,?) ON CONFLICT DO NOTHING', bindings: [$command->sellerRef, $command->environment, $evidence->sourceRef]);
                $context->lock(new LockRequest(
                    rank: LockRank::UsageSeries, table: 'billium_usage_source_head', keys: [$evidence->sourceRef], keyColumn: 'source_ref',
                ));
                $scope = ['seller_ref' => $command->sellerRef, 'environment' => $command->environment,
                    'source_ref' => $evidence->sourceRef, 'evidence_seq' => $evidence->evidenceSeq];
                $existing = $db->table('billium_usage_source_completeness')->where($scope)->first();
                if ($existing !== null && $existing->evidence_digest !== $evidence->evidenceDigest) {
                    throw UsageProblems::error('completeness_conflict');
                }
                if ($existing === null) {
                    $db->table('billium_usage_source_completeness')->insert([
                        ...$scope, 'status' => $evidence->status->value,
                        'range_start_micros' => $evidence->rangeStartMicros, 'range_end_micros' => $evidence->rangeEndMicros,
                        'watermark_micros' => $evidence->watermarkMicros, 'repair_or_replay_ref' => $evidence->repairOrReplayRef,
                        'evidence_digest' => $evidence->evidenceDigest,
                    ]);
                }

                return new FreezeReceipt(
                    payload: ['sourceRef' => $evidence->sourceRef, 'evidenceSeq' => $evidence->evidenceSeq, 'evidenceDigest' => $evidence->evidenceDigest],
                    class: 'usage.completeness_recorded',
                );
            },
        );
        $outcome->orThrow();

        return new FreezeReceipt(payload: $outcome->body, class: 'usage.completeness_recorded');
    }
}
