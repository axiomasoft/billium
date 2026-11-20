<?php

declare(strict_types=1);

namespace Billium\Collection\Contracts;

use Billium\Collection\Data\CollectionHostSignalView;
use Billium\Collection\Data\CollectionHostStatusSnapshot;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\EvaluateHostSignals;
use Billium\Collection\Data\RecordOutcomeSignal;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Durable signal-only host facts (grace, read_only, renewal outcome) for ProductCoordinator
 * and authoritative/stale/unknown host status projection.
 */
#[Api(since: '1.0')]
interface CollectionSignalService
{
    /** @return list<CollectionHostSignalView> */
    public function evaluatePolicySignals(EvaluateHostSignals $command): array;

    public function recordOutcomeSignal(RecordOutcomeSignal $command): CollectionHostSignalView;

    public function readHostStatus(
        CollectionScope $scope,
        string $caseRef,
        ?DateTimeImmutable $asOf = null,
        ?int $maxStalenessSeconds = null,
    ): CollectionHostStatusSnapshot;
}
