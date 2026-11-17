<?php

declare(strict_types=1);

namespace Billium\Usage\Data;

use Billium\Usage\Enums\CompletenessStatus;
use Billium\Usage\Internal\Canonical\CanonicalJson;
use Billium\Usage\Internal\Errors\UsageProblems;
use Packman\Attributes\Api;

#[Api]
final readonly class SourceCompleteness
{
    public function __construct(
        public string $sourceRef,
        public int $evidenceSeq,
        public CompletenessStatus $status,
        public int $rangeStartMicros,
        public int $rangeEndMicros,
        public int $watermarkMicros,
        public ?string $repairOrReplayRef,
        public string $evidenceDigest,
    ) {
        if ($sourceRef === '' || $evidenceSeq < 1 || $rangeStartMicros >= $rangeEndMicros
            || ($status !== CompletenessStatus::Complete && $status !== CompletenessStatus::Delayed && ($repairOrReplayRef === null || $repairOrReplayRef === ''))) {
            throw UsageProblems::error('completeness_invalid');
        }
        if ($evidenceDigest !== CanonicalJson::digest($this->payload())) {
            throw UsageProblems::error('completeness_digest_mismatch');
        }
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'sourceRef' => $this->sourceRef, 'evidenceSeq' => $this->evidenceSeq, 'status' => $this->status->value,
            'rangeStartMicros' => $this->rangeStartMicros, 'rangeEndMicros' => $this->rangeEndMicros,
            'watermarkMicros' => $this->watermarkMicros, 'repairOrReplayRef' => $this->repairOrReplayRef,
        ];
    }
}
