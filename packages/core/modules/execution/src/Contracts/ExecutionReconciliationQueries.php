<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Execution\Data\ExecutionRecoveryQueuePage;
use Billium\Execution\Data\ExecutionReviewQueuePage;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Enums\AttemptState;
use DateTimeImmutable;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface ExecutionReconciliationQueries
{
    public function dueRecovery(ExecutionScope $scope, string $sourceAuthority, AttemptState $state, DateTimeImmutable $asOf, int $limit = 100, ?string $cursor = null): ExecutionRecoveryQueuePage;

    public function openDiscrepancies(ExecutionScope $scope, string $sourceAuthority, string $status, DateTimeImmutable $createdBefore, int $limit = 100, ?string $cursor = null): ExecutionReviewQueuePage;

    public function dueReview(ExecutionScope $scope, string $sourceAuthority, DateTimeImmutable $asOf, int $limit = 100, ?string $cursor = null): ExecutionReviewQueuePage;
}
