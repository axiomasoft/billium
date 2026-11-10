<?php

declare(strict_types=1);

namespace Billium\Usage\Enums;

enum GaugePolicy: string
{
    case LatestAsOf = 'latest_as_of';
    case Max = 'max';
    case TimeWeighted = 'time_weighted';
}
