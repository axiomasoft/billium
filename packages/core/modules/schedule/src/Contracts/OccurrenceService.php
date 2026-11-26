<?php

declare(strict_types=1);

namespace Billium\Schedule\Contracts;

use Billium\Contracts\Data\Envelopes\CommandReceipt;
use Billium\Contracts\Data\Envelopes\EnsureBillingOccurrence;
use Billium\Schedule\Data\Commands\ApplyScheduleRevision;
use Billium\Schedule\Data\Commands\CancelSchedule;
use Billium\Schedule\Data\Commands\EnsureOccurrence;
use Billium\Schedule\Data\Commands\MaterializeNext;
use Billium\Schedule\Data\Commands\RecordScheduleFailure;
use Billium\Schedule\Data\OccurrenceReceipt;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(SpiKind::Call)]
interface OccurrenceService
{
    public function ensureOccurrence(EnsureOccurrence $command): OccurrenceReceipt;

    public function ensureBillingOccurrence(EnsureBillingOccurrence $command, PinnedOccurrenceInputs $inputs): CommandReceipt;

    public function applyScheduleRevision(ApplyScheduleRevision $command): OccurrenceReceipt;

    public function cancelSchedule(CancelSchedule $command): OccurrenceReceipt;

    public function materializeNext(MaterializeNext $command): OccurrenceReceipt;

    public function recordFailure(RecordScheduleFailure $command): OccurrenceReceipt;
}
