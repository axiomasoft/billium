<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use Packman\Attributes\Api;

/**
 * Summary returned by ImportBatchApplier::apply or ImportBatchApplier::resume.
 */
#[Api(since: '1.0')]
final readonly class ImportApplyResult
{
    /**
     * @param  array<string, int>  $counts
     * @param  list<ImportRecordApplyOutcome>  $outcomes
     */
    public function __construct(
        public int $batchPk,
        public string $batchId,
        public string $manifestDigest,
        public ImportBatchStatus $batchStatus,
        public int $processedSteps,
        public int $remainingSteps,
        public array $counts,
        public array $outcomes,
        public ?ImportReconciliationReport $reconciliation = null,
    ) {}

    /** @return array<string, mixed> */
    public function toWire(): array
    {
        $wire = [
            'batchPk' => $this->batchPk,
            'batchId' => $this->batchId,
            'manifestDigest' => $this->manifestDigest,
            'batchStatus' => $this->batchStatus->value,
            'processedSteps' => $this->processedSteps,
            'remainingSteps' => $this->remainingSteps,
            'counts' => $this->counts,
            'outcomes' => array_map(static fn (ImportRecordApplyOutcome $o): array => $o->toWire(), $this->outcomes),
        ];
        if ($this->reconciliation !== null) {
            $wire['reconciliation'] = $this->reconciliation->toWire();
        }

        return $wire;
    }
}
