<?php

declare(strict_types=1);

namespace Billium\Contracts\Attributes;

use Attribute;

/**
 * Marks an extension point of the package and which side implements it.
 */
#[Api]
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Spi
{
    public function __construct(public SpiKind $kind) {}
}
