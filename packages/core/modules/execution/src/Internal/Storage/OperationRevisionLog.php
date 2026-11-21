<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Storage;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Data\CapacitySnapshot;
use Billium\Execution\Data\OperationRecord;
use DateTimeInterface;
use Packman\Attributes\Internal;

/** Append-only journal of operation revisions: what was observed and which capacity it left applied. */
#[Internal]
final readonly class OperationRevisionLog
{
    public function __construct(private ExecutionTables $tables) {}

    /** @param list<string> $evidenceRefs */
    public function append(TransactionContext $context, OperationRecord $operation, int $revision, string $materialDigest, mixed $observed,
        CapacitySnapshot $applied, string $exposure, array $evidenceRefs, string $receiptRef, DateTimeInterface $occurredAt): void
    {
        $this->tables->database($context)->table('billium_execution_operation_revision')->insert($this->tables->callerScope($context) + [
            'operation_ref' => $operation->operationRef, 'revision' => $revision, 'material_digest' => $materialDigest,
            'requested_minor' => $operation->requested->minor, 'currency' => $operation->requested->currency->code,
            'observed' => ExecutionCodec::encode($observed), 'last_consistent_applied' => ExecutionCodec::encode($applied),
            'financial_hold' => ExecutionCodec::encode($applied->financialHold), 'exposure' => $exposure,
            'evidence_refs' => ExecutionCodec::encode($evidenceRefs), 'receipt_ref' => $receiptRef, 'occurred_at' => $occurredAt,
        ]);
    }
}
