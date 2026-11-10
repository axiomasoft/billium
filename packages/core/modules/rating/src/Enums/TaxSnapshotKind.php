<?php

declare(strict_types=1);

namespace Billium\Rating\Enums;

use Billium\Contracts\Attributes\Api;

#[Api]
enum TaxSnapshotKind: string
{
    case Standard = 'standard';
    case Correction = 'correction';
}
