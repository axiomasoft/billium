<?php

declare(strict_types=1);

namespace Billium\Contracts\Attributes;

use Attribute;
use Packman\Attributes\Api as PackmanApi;

/**
 * Marks an extension point of the package and which side implements it.
 *
 * @deprecated Use {@see \Packman\Attributes\Spi} instead.
 */
#[PackmanApi(since: '1.0')]
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Spi
{
    public function __construct(public SpiKind $kind) {}
}
