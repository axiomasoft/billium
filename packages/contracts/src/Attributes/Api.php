<?php

declare(strict_types=1);

namespace Billium\Contracts\Attributes;

use Attribute;

/**
 * Marks a type as public contract API: its signature follows the versioning rules of the package.
 */
#[Api]
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Api {}
