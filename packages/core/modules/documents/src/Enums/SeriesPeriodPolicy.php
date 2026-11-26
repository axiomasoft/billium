<?php

declare(strict_types=1);

namespace Billium\Documents\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum SeriesPeriodPolicy: string
{
    case None = 'none';
    case Yearly = 'yearly';
    case Monthly = 'monthly';
}
