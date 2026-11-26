<?php

declare(strict_types=1);

namespace Billium\Contracts\Attributes;

use Attribute;
use Packman\Attributes\Api as PackmanApi;

/**
 * Marks a type as public contract API: its signature follows the versioning rules of the package.
 *
 * @deprecated Use {@see PackmanApi} instead.
 */
#[PackmanApi(since: '1.0')]
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Api {}
