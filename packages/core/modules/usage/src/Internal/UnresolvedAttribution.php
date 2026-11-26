<?php

declare(strict_types=1);

namespace Billium\Usage\Internal;

use Billium\Contracts\Data\Envelopes\UsageFactV2;
use Billium\Core\Data\Runtime\Caller;
use Billium\Usage\Contracts\UsageAttribution;
use Billium\Usage\Data\AttributionSnapshot;
use Packman\Attributes\Internal;

#[Internal]
final readonly class UnresolvedAttribution implements UsageAttribution
{
    public function resolve(Caller $caller, UsageFactV2 $fact): ?AttributionSnapshot
    {
        return null;
    }
}
