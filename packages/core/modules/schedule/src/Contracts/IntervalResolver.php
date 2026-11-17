<?php

declare(strict_types=1);

namespace Billium\Schedule\Contracts;

use Billium\Schedule\Data\ResolvedInterval;
use Billium\Schedule\Data\ScheduleRevisionTerms;
use Packman\Attributes\Api;

#[Api]
interface IntervalResolver
{
    public function resolve(ScheduleRevisionTerms $terms, int $ordinal): ResolvedInterval;
}
