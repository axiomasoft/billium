<?php

declare(strict_types=1);

namespace Billium\Usage\Contracts;

use Billium\Contracts\Data\Envelopes\UsageFactV2;
use Billium\Core\Data\Runtime\Caller;
use Billium\Usage\Data\AttributionSnapshot;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** D13/D56: host-owned lookup, outside the root Tx. Null means unresolved, never permission to invent a payer.
 * The host verifies normalized provenance/source selection, and resolves the immutable event-time mapping and
 * original commercial period. It must return historical mapping versions after a payer transfer.
 */
#[Spi(SpiKind::Implement)]
interface UsageAttribution
{
    public function resolve(Caller $caller, UsageFactV2 $fact): ?AttributionSnapshot;
}
