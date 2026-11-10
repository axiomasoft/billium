<?php

declare(strict_types=1);

namespace Billium\Schedule\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Schedule\Internal\ScheduleProblems;
use DateTimeZone;

/** D56; owner schedule. Explicit strategies; no process-zone or fixed-30-day fallback. */
#[Api]
final readonly class PeriodPolicy
{
    public function __construct(
        public string $timezone,
        public string $frequency,
        public int $intervalCount = 1,
        public bool $endOfMonth = false,
        public string $shortMonthPolicy = 'clamp_preserve_anchor',
        public string $dstGapPolicy = 'shift_forward',
        public string $dstFoldPolicy = 'earlier_offset',
        public string $chargeTiming = 'advance',
        public int $cutoffSeconds = 0,
        public string $calendarPolicy = 'anchored_calendar/1',
        public string $prorationPolicy = 'whole_interval/1',
    ) {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)
            || ! in_array($frequency, ['month', 'year'], true)
            || $intervalCount < 1 || $intervalCount > 120
            || $shortMonthPolicy !== 'clamp_preserve_anchor'
            || ! in_array($dstGapPolicy, ['shift_forward', 'reject'], true)
            || ! in_array($dstFoldPolicy, ['earlier_offset', 'later_offset'], true)
            || ! in_array($chargeTiming, ['advance', 'arrears'], true)
            || $cutoffSeconds < 0 || $cutoffSeconds > 31_536_000
            || $calendarPolicy !== 'anchored_calendar/1' || $prorationPolicy !== 'whole_interval/1') {
            ScheduleProblems::refuse('unsupported_period_policy');
        }
    }

    /** @return array<string, int|bool|string> */
    public function toArray(): array
    {
        return ['timezone' => $this->timezone, 'frequency' => $this->frequency, 'intervalCount' => $this->intervalCount,
            'endOfMonth' => $this->endOfMonth, 'shortMonthPolicy' => $this->shortMonthPolicy,
            'dstGapPolicy' => $this->dstGapPolicy, 'dstFoldPolicy' => $this->dstFoldPolicy,
            'chargeTiming' => $this->chargeTiming, 'cutoffSeconds' => $this->cutoffSeconds,
            'calendarPolicy' => $this->calendarPolicy, 'prorationPolicy' => $this->prorationPolicy];
    }
}
