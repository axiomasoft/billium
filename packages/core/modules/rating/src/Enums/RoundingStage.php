<?php

declare(strict_types=1);

namespace Billium\Rating\Enums;

use Billium\Contracts\Attributes\Api;

#[Api]
enum RoundingStage: string
{
    case AfterTax = 'after_tax';
}
