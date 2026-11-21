<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Storage;

use Billium\Contracts\Contracts\Codec;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\ObservationEmission;
use Billium\Execution\Data\ObservationPartition;
use LogicException;
use Packman\Attributes\Internal;

/** Observation partitions, their source sequences and the emissions published from them. */
#[Internal]
final readonly class ObservationStorage
{
    public function __construct(private ExecutionTables $tables) {}

    public function ensureObservationPartition(TransactionContext $transactionContext, ExecutionScope $executionScope, string $sourceAuthority, string $lineage, string $rootRef): ObservationPartition
    {
        $this->tables->financial($transactionContext);
        $this->tables->assertScope($executionScope, $transactionContext, $sourceAuthority);
        $root = $this->tables->admittedOperation($transactionContext, $rootRef);
        if ($root->operationRef !== $root->rootOperationRef) {
            ExecutionTables::refuse('Partition requires original root');
        }

        return $this->persistObservationPartition($transactionContext, $executionScope, $sourceAuthority, $lineage, $rootRef);
    }

    public function prepareObservationPartition(TransactionContext $context, FrozenBinding $binding, string $lineage): ObservationPartition
    {
        $this->tables->financial($context);
        $this->tables->assertScope($binding->scope, $context, $binding->executionProfile);
        if ($binding->origin === null || $lineage === '' || strlen($lineage) > 200) {
            ExecutionTables::refuse('Initial partition requires an authenticated original basis and complete lineage');
        }

        return $this->persistObservationPartition($context, $binding->scope, $binding->executionProfile, $lineage, $binding->rootOperationRef);
    }

    public function nextObservationSequence(TransactionContext $transactionContext, int $partitionId): int
    {
        $this->tables->financial($transactionContext);
        if (! $this->tables->holds($transactionContext, 'billium_execution_observation_partition:'.$partitionId)) {
            throw new LogicException('Observation partition lock required');
        }
        $q = $this->tables->callerQuery('observation_partition', $transactionContext)->where('id', $partitionId)->where('source_authority', $this->tables->selectedScope($transactionContext)->sourceAuthority);
        $partition = (clone $q)->first() ?? throw new LogicException('Partition absent');
        $this->tables->requireLock($transactionContext, ExecutionRows::text($partition->root_operation_ref));
        $q->increment('last_sequence');

        return ExecutionRows::integer($q->value('last_sequence'));
    }

    public function appendEmission(TransactionContext $transactionContext, ObservationEmission $observationEmission): void
    {
        $this->tables->financial($transactionContext);
        $this->tables->assertScope($observationEmission->scope, $transactionContext, $observationEmission->sourceAuthority);
        $op = $this->tables->operation($observationEmission->scope, $observationEmission->operationRef, $transactionContext) ?? throw new LogicException('Emission operation absent');
        $p = $observationEmission->payload;
        if ([$p->installationRef, $p->sellerRef, $p->environment, $p->sourceAuthority, $p->sourceLineage, $p->partitionRef, $p->sourceSequence, $p->operationRef, $p->operationRevision, $p->eventId, $p->contentDigest] !== [$observationEmission->scope->installationRef, $observationEmission->scope->sellerRef, $observationEmission->scope->environment, $observationEmission->sourceAuthority, $observationEmission->sourceLineage, $observationEmission->partitionRef, $observationEmission->sourceSequence, $observationEmission->operationRef, $observationEmission->operationRevision, $observationEmission->eventId, $observationEmission->payloadDigest] || $op->revision !== $observationEmission->operationRevision || (new Codec)->digestOf($p) !== $observationEmission->payloadDigest) {
            ExecutionTables::refuse('Emission differs from immutable wire observation');
        }

        $this->tables->requireLock($transactionContext, $op->rootOperationRef);
        $this->tables->database($transactionContext)->table('billium_execution_observation_emission')->insert(ExecutionTables::scope($observationEmission->scope) + ['emission_ref' => $observationEmission->emissionRef, 'source_authority' => $observationEmission->sourceAuthority, 'source_lineage' => $observationEmission->sourceLineage, 'partition_ref' => $observationEmission->partitionRef, 'source_sequence' => $observationEmission->sourceSequence, 'root_operation_ref' => $op->rootOperationRef, 'operation_ref' => $observationEmission->operationRef, 'operation_revision' => $observationEmission->operationRevision, 'command_ref' => $observationEmission->commandRef, 'event_id' => $observationEmission->eventId, 'payload_digest' => $observationEmission->payloadDigest, 'payload' => ExecutionCodec::encode($observationEmission->payload), 'command_row_id' => $observationEmission->commandRowId]);
    }

    public function bindEmissionCommandRow(TransactionContext $transactionContext, string $commandRef, int $commandRowId): void
    {
        $this->tables->financial($transactionContext);
        $q = $this->tables->callerQuery('observation_emission', $transactionContext)->where('command_ref', $commandRef);
        $row = (clone $q)->first() ?? throw new LogicException('Emission absent');
        $this->tables->requireLock($transactionContext, ExecutionRows::text($row->root_operation_ref));
        if ($commandRowId <= 0 || $q->whereNull('command_row_id')->update(['command_row_id' => $commandRowId]) !== 1) {
            throw new LogicException('Emission command row already bound or invalid');
        }
    }

    private function persistObservationPartition(TransactionContext $transactionContext, ExecutionScope $executionScope, string $sourceAuthority, string $lineage, string $rootRef): ObservationPartition
    {
        $map = ['schema' => 'billium.execution.observation-partition.v1', 'installationRef' => $executionScope->installationRef, 'sellerRef' => $executionScope->sellerRef, 'environment' => $executionScope->environment, 'sourceAuthority' => $sourceAuthority, 'sourceLineage' => $lineage, 'rootOperationRef' => $rootRef];
        $part = 'execution-charge:'.hash('sha256', (new Codec)->canonicalize((object) $map));
        $this->tables->database($transactionContext)->table('billium_execution_observation_partition')->insertOrIgnore(ExecutionTables::scope($executionScope) + ['source_authority' => $sourceAuthority, 'source_lineage' => $lineage, 'root_operation_ref' => $rootRef, 'partition_ref' => $part]);
        $r = $this->tables->query('observation_partition', $executionScope, $transactionContext)->where('source_authority', $sourceAuthority)->where('source_lineage', $lineage)->where('root_operation_ref', $rootRef)->first() ?? throw new LogicException('Partition absent');

        return new ObservationPartition(ExecutionRows::integer($r->id), $executionScope, $sourceAuthority, $lineage, $rootRef, ExecutionRows::text($r->partition_ref), ExecutionRows::integer($r->last_sequence));
    }
}
