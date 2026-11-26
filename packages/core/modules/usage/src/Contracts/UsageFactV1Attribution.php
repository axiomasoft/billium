<?php

declare(strict_types=1);

namespace Billium\Usage\Contracts;

use Billium\Contracts\Data\Envelopes\UsageFact;
use Billium\Core\Data\Runtime\Caller;
use Billium\Usage\Data\UsageFactV1Inputs;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface UsageFactV1Attribution
{
    /** Resolve immutable original-period attribution and verify source provenance before supplying normalized evidence. */
    public function resolve(Caller $caller, UsageFact $fact): ?UsageFactV1Inputs;
}
