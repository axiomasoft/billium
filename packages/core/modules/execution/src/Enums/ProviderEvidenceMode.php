<?php

declare(strict_types=1);

namespace Billium\Execution\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum ProviderEvidenceMode: string
{
    case CumulativeSnapshot = 'cumulative_snapshot';
    case StableEffectId = 'stable_effect_id';
}
