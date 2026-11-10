<?php

declare(strict_types=1);

namespace Billium\Schedule\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Schedule\Data\ResolvedInterval;
use Billium\Schedule\Data\ScheduleRevisionTerms;

#[Api]
interface IntervalResolver
{
    public function resolve(ScheduleRevisionTerms $terms, int $ordinal): ResolvedInterval;
}
